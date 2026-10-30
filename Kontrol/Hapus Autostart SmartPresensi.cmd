@echo off
title SmartPresensi - Hapus Autostart
:: Aksi: cabut pemasangan Windows Service (tidak autostart lagi)  -  Memanggil: scripts\service-cli.ps1 -Action uninstall
cd /d "%~dp0.."

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\service-cli.ps1" -Action uninstall

echo.
echo Tekan tombol apa saja untuk menutup...
pause >nul
