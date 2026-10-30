package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

const version = "0.1.0"

type app struct {
	mode       string
	token      string
	port       int
	bundleRoot string
	startedAt  time.Time
	shutdown   chan struct{}
}

func main() {
	mode := flag.String("mode", "install", "mode: install|repair|restore")
	port := flag.Int("port", 0, "port (0 = otomatis)")
	noBrowser := flag.Bool("no-browser", false, "jangan buka browser otomatis")
	bundle := flag.String("bundle", "", "root bundle (default: folder file exe)")
	flag.Parse()

	a := &app{
		mode:      *mode,
		token:     newToken(),
		startedAt: time.Now(),
		shutdown:  make(chan struct{}),
	}
	a.bundleRoot = resolveBundleRoot(*bundle)

	ln, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", *port))
	if err != nil {
		log.Fatalf("gagal membuka port: %v", err)
	}
	a.port = ln.Addr().(*net.TCPAddr).Port
	url := fmt.Sprintf("http://127.0.0.1:%d/", a.port)

	fmt.Println("=====================================================")
	fmt.Printf(" SmartPresensi Setup Assistant %s\n", version)
	fmt.Println("=====================================================")
	fmt.Printf(" Mode    : %s\n", a.mode)
	fmt.Printf(" Bundle  : %s\n", a.bundleRoot)
	fmt.Printf(" URL     : %s\n", url)
	fmt.Printf(" Token   : %s\n", a.token)
	fmt.Println(" (tutup proses ini atau klik Selesai pada wizard untuk berhenti)")

	srv := &http.Server{Handler: a.routes()}
	go func() {
		if err := srv.Serve(ln); err != nil && err != http.ErrServerClosed {
			log.Fatalf("server berhenti: %v", err)
		}
	}()

	if !*noBrowser {
		go openBrowser(url)
	}

	<-a.shutdown
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	_ = srv.Shutdown(ctx)
	fmt.Println("Setup assistant dihentikan.")
}

func newToken() string {
	b := make([]byte, 24)
	if _, err := rand.Read(b); err != nil {
		log.Fatalf("gagal membuat token: %v", err)
	}
	return hex.EncodeToString(b)
}

func resolveBundleRoot(flagVal string) string {
	if flagVal != "" {
		if abs, err := filepath.Abs(flagVal); err == nil {
			return abs
		}
		return flagVal
	}
	if exe, err := os.Executable(); err == nil {
		dir := filepath.Dir(exe)
		if isBundleRoot(dir) {
			return dir
		}
		if parent := filepath.Dir(dir); isBundleRoot(parent) {
			return parent
		}
	}
	wd, _ := os.Getwd()
	return wd
}

func isBundleRoot(dir string) bool {
	for _, sub := range []string{"runtime", "app"} {
		fi, err := os.Stat(filepath.Join(dir, sub))
		if err != nil || !fi.IsDir() {
			return false
		}
	}
	return true
}
