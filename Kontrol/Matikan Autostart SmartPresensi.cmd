@echo off
title SmartPresensi - Matikan Autostart
:: Aksi: set Manual lalu hentikan (tidak jalan otomatis saat boot)  -  Memanggil: scripts\service-cli.ps1 -Action pause
cd /d "%~dp0.."

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\service-cli.ps1" -Action pause

echo.
echo Tekan tombol apa saja untuk menutup...
pause >nul
