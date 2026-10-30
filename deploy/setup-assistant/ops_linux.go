//go:build linux

package main

import (
	"os"
	"os/exec"
	"strings"
)

func isAdmin() bool { return os.Geteuid() == 0 }

func openBrowser(url string) { _ = exec.Command("xdg-open", url).Start() }

func osDescription() string {
	raw, err := os.ReadFile("/etc/os-release")
	if err == nil {
		for _, line := range strings.Split(string(raw), "\n") {
			if strings.HasPrefix(line, "PRETTY_NAME=") {
				return strings.Trim(strings.TrimPrefix(line, "PRETTY_NAME="), "\"")
			}
		}
	}
	return "Linux"
}

func adminValue() string {
	if isAdmin() {
		return "Aktif (root)"
	}
	return "Tidak aktif"
}

func adminRemedy() string {
	return "Jalankan ulang start-setup.sh dengan sudo agar bisa memasang layanan sistem."
}
