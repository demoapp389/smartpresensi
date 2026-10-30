package main

import (
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
)

func (a *app) handleState(w http.ResponseWriter, r *http.Request) {
	host, _ := hostname()
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":         true,
		"version":    version,
		"mode":       a.mode,
		"os":         runtime.GOOS,
		"arch":       runtime.GOARCH,
		"hostname":   host,
		"admin":      isAdmin(),
		"bundleRoot": a.bundleRoot,
		"components": a.detectComponents(),
		"config":     a.detectConfig(),
	})
}

// detectConfig membaca konfigurasi yang sudah ada (bila ada) agar wizard dapat
// pra-mengisi nilai saat ini dan tidak menimpa port/database yang telah dipakai.
func (a *app) detectConfig() map[string]any {
	cfg := map[string]any{}

	envPath := filepath.Join(a.bundleRoot, "app", ".env")
	if data, err := os.ReadFile(envPath); err == nil {
		env := parseEnv(string(data))
		if v, ok := env["APP_URL"]; ok {
			cfg["appUrl"] = v
		}
		if v, ok := env["SCHOOL_NAME"]; ok {
			cfg["schoolName"] = v
		}
		if v, ok := env["DB_DATABASE"]; ok {
			cfg["dbName"] = v
		}
		if v, ok := env["DB_PORT"]; ok {
			if n, err := strconv.Atoi(strings.TrimSpace(v)); err == nil {
				cfg["dbPort"] = n
			}
		}
	}

	myIni := filepath.Join(a.bundleRoot, "runtime", "mysql", "my.ini")
	if n := firstInt(myIni, `(?m)^\s*port\s*=\s*(\d+)`); n > 0 {
		cfg["dbPort"] = n
	}

	httpd := filepath.Join(a.bundleRoot, "runtime", "apache", "conf", "httpd.conf")
	if n := firstInt(httpd, `(?m)^\s*Listen\s+(\d+)`); n > 0 {
		cfg["webPort"] = n
	}

	return cfg
}

func parseEnv(content string) map[string]string {
	out := map[string]string{}
	for _, line := range strings.Split(content, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		i := strings.IndexByte(line, '=')
		if i <= 0 {
			continue
		}
		k := strings.TrimSpace(line[:i])
		v := strings.TrimSpace(line[i+1:])
		if len(v) >= 2 {
			if (v[0] == '"' && v[len(v)-1] == '"') || (v[0] == '\'' && v[len(v)-1] == '\'') {
				v = v[1 : len(v)-1]
			}
		}
		out[k] = v
	}
	return out
}

func firstInt(path, pattern string) int {
	data, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	re := regexp.MustCompile(pattern)
	m := re.FindStringSubmatch(string(data))
	if len(m) < 2 {
		return 0
	}
	n, err := strconv.Atoi(m[1])
	if err != nil {
		return 0
	}
	return n
}

func (a *app) handleCheckSystem(w http.ResponseWriter, r *http.Request) {
	host, _ := hostname()
	checks := []map[string]any{
		{
			"key":   "os",
			"label": "Sistem Operasi",
			"ok":    true,
			"value": osDescription(),
		},
		{
			"key":   "arch",
			"label": "Arsitektur",
			"ok":    runtime.GOARCH == "amd64",
			"value": runtime.GOARCH,
		},
		{
			"key":         "admin",
			"label":       "Hak Administrator",
			"ok":          isAdmin(),
			"value":       adminValue(),
			"remedy":      adminRemedy(),
			"remediation": adminRemedy(),
		},
		{
			"key":   "hostname",
			"label": "Nama Komputer",
			"ok":    true,
			"value": host,
		},
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "checks": checks})
}

func (a *app) handleCheckDependencies(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "components": a.detectComponents()})
}

func (a *app) handleHealth(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":        true,
		"checks":    []any{},
		"message":   "Pemeriksaan kesehatan akan tersedia pada Fase 3 (step engine).",
		"available": false,
	})
}

func (a *app) handleShutdown(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "message": "Server dihentikan."})
	select {
	case <-a.shutdown:
	default:
		close(a.shutdown)
	}
}
