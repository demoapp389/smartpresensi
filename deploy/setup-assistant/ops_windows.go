//go:build windows

package main

import (
	"os/exec"
	"strings"
	"syscall"
)

func isAdmin() bool {
	cmd := exec.Command("whoami", "/groups")
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.Output()
	if err != nil {
		return false
	}
	return strings.Contains(string(out), "S-1-16-12288")
}

func openBrowser(url string) {
	cmd := exec.Command("rundll32", "url.dll,FileProtocolHandler", url)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	_ = cmd.Start()
}

func osDescription() string {
	return "Windows (Win64)"
}

func adminValue() string {
	if isAdmin() {
		return "Aktif"
	}
	return "Tidak aktif"
}

func adminRemedy() string {
	return "Tutup jendela ini, klik kanan start-setup.ps1 > Run with PowerShell (atau setujui UAC), lalu mulai ulang."
}
