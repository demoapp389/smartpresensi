@echo off
title SmartPresensi - Status
:: Aksi: tampilkan status layanan & URL  -  Memanggil: scripts\service-cli.ps1 -Action status
cd /d "%~dp0.."

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\service-cli.ps1" -Action status

echo.
echo Tekan tombol apa saja untuk menutup...
pause >nul
