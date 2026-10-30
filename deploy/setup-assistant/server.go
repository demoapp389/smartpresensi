package main

import (
	"encoding/json"
	"fmt"
	"io/fs"
	"net/http"
	"strings"
)

func (a *app) routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/", a.handleIndex)
	mux.Handle("/assets/", a.assetHandler())
	mux.HandleFunc("/api/state", a.guard(a.handleState))
	mux.HandleFunc("/api/check/system", a.guard(a.handleCheckSystem))
	mux.HandleFunc("/api/check/dependencies", a.guard(a.handleCheckDependencies))
	mux.HandleFunc("/api/apply", a.guard(a.handleApply))
	mux.HandleFunc("/api/apply/progress", a.guard(a.handleApplyProgress))
	mux.HandleFunc("/api/health", a.guard(a.handleHealth))
	mux.HandleFunc("/api/shutdown", a.guard(a.handleShutdown))
	return a.withCommonHeaders(mux)
}

// guard melindungi seluruh endpoint API: hanya POST, token sesi wajib cocok,
// dan Origin (bila ada) harus berasal dari server lokal ini.
func (a *app) guard(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeErr(w, http.StatusMethodNotAllowed, "metode tidak diizinkan")
			return
		}
		tok := r.Header.Get("X-Setup-Token")
		if tok == "" {
			tok = r.URL.Query().Get("token")
		}
		if tok != a.token {
			writeErr(w, http.StatusForbidden, "token sesi tidak valid")
			return
		}
		if origin := r.Header.Get("Origin"); origin != "" {
			host := fmt.Sprintf(":%d", a.port)
			if !strings.HasSuffix(origin, host) {
				writeErr(w, http.StatusForbidden, "origin tidak diizinkan")
				return
			}
		}
		next(w, r)
	}
}

func (a *app) handleIndex(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	raw, err := webFS.ReadFile("web/index.html")
	if err != nil {
		http.Error(w, "aset wizard tidak ditemukan", http.StatusInternalServerError)
		return
	}
	html := strings.ReplaceAll(string(raw), "__SETUP_TOKEN__", a.token)
	html = strings.ReplaceAll(html, "__SETUP_MODE__", a.mode)
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Write([]byte(html))
}

func (a *app) assetHandler() http.Handler {
	sub, err := fs.Sub(webFS, "web/dist/assets")
	if err != nil {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			http.Error(w, "aset tidak tersedia", http.StatusInternalServerError)
		})
	}
	return http.StripPrefix("/assets/", http.FileServer(http.FS(sub)))
}

func (a *app) withCommonHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("Referrer-Policy", "no-referrer")
		h.Set("X-Frame-Options", "DENY")
		h.Set("Cache-Control", "no-store")
		h.Set("Content-Security-Policy",
			"default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-eval'; connect-src 'self'")
		next.ServeHTTP(w, r)
	})
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeErr(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]any{"ok": false, "error": msg})
}
