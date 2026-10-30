package main

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

type component struct {
	Key      string `json:"key"`
	Name     string `json:"name"`
	Path     string `json:"path"`
	Present  bool   `json:"present"`
	Runnable bool   `json:"runnable"`
	Version  string `json:"version"`
	Error    string `json:"error"`
	Source   string `json:"source"`
}

type componentSpec struct {
	Key  string
	Name string
	Rel  string
	Args []string
}

func componentSpecs() []componentSpec {
	if runtime.GOOS == "windows" {
		return []componentSpec{
			{"php", "PHP", `runtime\php\php.exe`, []string{"-v"}},
			{"apache", "Apache", `runtime\apache\bin\httpd.exe`, []string{"-v"}},
			{"mysql", "MySQL", `runtime\mysql\bin\mysqld.exe`, []string{"--version"}},
		}
	}
	return nil
}

func (a *app) detectComponents() []component {
	out := []component{}
	for _, s := range componentSpecs() {
		full := filepath.Join(a.bundleRoot, s.Rel)
		c := component{Key: s.Key, Name: s.Name, Path: full, Source: "bundle"}
		if fi, err := os.Stat(full); err == nil && !fi.IsDir() {
			c.Present = true
			ver, err := runCapture(full, s.Args, 10*time.Second)
			if err != nil {
				c.Error = strings.TrimSpace(err.Error())
			} else {
				c.Runnable = true
				c.Version = firstLine(ver)
			}
		} else {
			c.Error = "tidak ditemukan di bundle"
		}
		out = append(out, c)
	}
	return out
}

func runCapture(path string, args []string, timeout time.Duration) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, path, args...)
	raw, err := cmd.CombinedOutput()
	return strings.TrimSpace(string(raw)), err
}

func firstLine(s string) string {
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return strings.TrimSpace(s[:i])
	}
	return strings.TrimSpace(s)
}

func hostname() (string, error) { return os.Hostname() }
