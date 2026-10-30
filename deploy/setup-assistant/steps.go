package main

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Aksi yang diizinkan dipanggil dari wizard. Tidak ada penerusan shell bebas:
// setiap aksi adalah operasi terdefinisi di setup-steps.ps1.
var allowedActions = map[string]bool{
	"check-admin":                true,
	"check-ports":                true,
	"check-services":             true,
	"apply-config":               true,
	"install-vc":                 true,
	"init-mysql":                 true,
	"install-mysql-service":      true,
	"setup-database":             true,
	"reset-db-password":          true,
	"install-apache-service":     true,
	"install-supervisor-service": true,
	"setup-app":                  true,
	"create-admin":               true,
	"register-com":               true,
	"configure-firewall":         true,
	"repair":                     true,
	"health":                     true,
	"diagnostic":                 true,
	"backup":                     true,
	"restore":                    true,
	"apply-all":                  true,
}

func (a *app) stepScript() (string, error) {
	candidates := []string{
		filepath.Join(a.bundleRoot, "steps", "setup-steps.ps1"),
		filepath.Join(a.bundleRoot, "deploy", "steps", "setup-steps.ps1"),
	}
	for _, c := range candidates {
		if _, err := os.Stat(c); err == nil {
			return c, nil
		}
	}
	return "", fmt.Errorf("setup-steps.ps1 tidak ditemukan di bundle")
}

// runStep menjalankan aksi secara sinkron (tanpa pelaporan progres).
func (a *app) runStep(action string, params map[string]any) (map[string]any, error) {
	return a.runStepStreaming(action, params, nil)
}

// runStepStreaming menjalankan step engine dan memanggil onProgress untuk setiap
// baris penanda "##PROGRESS {json}" pada stderr. stdout tetap satu objek JSON.
func (a *app) runStepStreaming(action string, params map[string]any, onProgress func(json.RawMessage)) (map[string]any, error) {
	if !allowedActions[action] {
		return nil, fmt.Errorf("aksi tidak diizinkan: %s", action)
	}
	script, err := a.stepScript()
	if err != nil {
		return nil, err
	}
	if params == nil {
		params = map[string]any{}
	}

	pf, err := os.CreateTemp("", "sp-payload-*.json")
	if err != nil {
		return nil, err
	}
	defer os.Remove(pf.Name())
	if err := json.NewEncoder(pf).Encode(params); err != nil {
		pf.Close()
		return nil, err
	}
	pf.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 45*time.Minute)
	defer cancel()

	cmd := exec.CommandContext(ctx,
		"powershell.exe", "-NoProfile", "-ExecutionPolicy", "Bypass",
		"-File", script, "-Action", action, "-BundleRoot", a.bundleRoot, "-PayloadFile", pf.Name())

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		return nil, err
	}
	if err := cmd.Start(); err != nil {
		return nil, err
	}

	var wg sync.WaitGroup
	var outBytes []byte
	wg.Add(2)
	go func() {
		defer wg.Done()
		outBytes, _ = io.ReadAll(stdout)
	}()
	go func() {
		defer wg.Done()
		sc := bufio.NewScanner(stderr)
		sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)
		for sc.Scan() {
			line := sc.Text()
			if strings.HasPrefix(line, "##PROGRESS ") && onProgress != nil {
				onProgress(json.RawMessage(strings.TrimPrefix(line, "##PROGRESS ")))
			}
		}
	}()
	wg.Wait()
	_ = cmd.Wait()

	var res map[string]any
	if jerr := json.Unmarshal(outBytes, &res); jerr != nil {
		return nil, fmt.Errorf("output step engine tidak valid: %v | %s", jerr, truncate(strings.TrimSpace(string(outBytes)), 400))
	}
	return res, nil
}

// --- Job async untuk instalasi (apply-all) ----------------------------------

type stepJob struct {
	mu       sync.Mutex
	Action   string
	Total    int
	Current  int
	Step     string
	Done     bool
	OK       bool
	Steps    []map[string]any
	Warnings []string
	Error    string
}

var (
	jobsMu sync.Mutex
	jobs   = map[string]*stepJob{}
)

func newJobID() string {
	b := make([]byte, 12)
	if _, err := rand.Read(b); err != nil {
		return fmt.Sprintf("job-%d", time.Now().UnixNano())
	}
	return hex.EncodeToString(b)
}

func (a *app) runInstallJob(id string, j *stepJob, params map[string]any) {
	res, err := a.runStepStreaming("apply-all", params, func(raw json.RawMessage) {
		var p struct {
			I      int    `json:"i"`
			Total  int    `json:"total"`
			Action string `json:"action"`
			State  string `json:"state"`
			OK     *bool  `json:"ok"`
		}
		if json.Unmarshal(raw, &p) != nil {
			return
		}
		j.mu.Lock()
		if p.Total > 0 {
			j.Total = p.Total
		}
		if p.I > 0 {
			j.Current = p.I
		}
		if p.Action != "" {
			j.Step = p.Action
		}
		j.mu.Unlock()
	})

	j.mu.Lock()
	defer j.mu.Unlock()
	j.Done = true
	j.Current = j.Total
	if err != nil {
		j.OK = false
		j.Error = err.Error()
		return
	}
	j.OK = true
	if v, ok := res["ok"].(bool); ok {
		j.OK = v
	}
	if arr, ok := res["steps"].([]any); ok {
		for _, it := range arr {
			if m, ok := it.(map[string]any); ok {
				j.Steps = append(j.Steps, m)
			}
		}
	}
	if arr, ok := res["warnings"].([]any); ok {
		for _, w := range arr {
			j.Warnings = append(j.Warnings, fmt.Sprint(w))
		}
	}
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}

func (a *app) handleApply(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Action string         `json:"action"`
		Params map[string]any `json:"params"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		writeErr(w, http.StatusBadRequest, "body permintaan tidak valid")
		return
	}

	if body.Action == "apply-all" {
		j := &stepJob{Action: body.Action}
		id := newJobID()
		jobsMu.Lock()
		jobs[id] = j
		jobsMu.Unlock()
		go a.runInstallJob(id, j, body.Params)
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "jobId": id})
		return
	}

	res, err := a.runStep(body.Action, body.Params)
	if err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, res)
}

func (a *app) handleApplyProgress(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Job string `json:"job"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		writeErr(w, http.StatusBadRequest, "body permintaan tidak valid")
		return
	}
	jobsMu.Lock()
	j := jobs[body.Job]
	jobsMu.Unlock()
	if j == nil {
		writeErr(w, http.StatusNotFound, "job tidak ditemukan")
		return
	}
	j.mu.Lock()
	defer j.mu.Unlock()
	warnings := j.Warnings
	if warnings == nil {
		warnings = []string{}
	}
	steps := j.Steps
	if steps == nil {
		steps = []map[string]any{}
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":       true,
		"done":     j.Done,
		"current":  j.Current,
		"total":    j.Total,
		"step":     j.Step,
		"resultOk": j.OK,
		"steps":    steps,
		"warnings": warnings,
		"error":    j.Error,
	})
}
