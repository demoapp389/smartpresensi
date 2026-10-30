@echo off
title SmartPresensi - Info Jaringan
:: Aksi: tampilkan info jaringan/LAN untuk akses dari HP  -  Memanggil: scripts\service-cli.ps1 -Action network
cd /d "%~dp0.."

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\service-cli.ps1" -Action network

echo.
echo Tekan tombol apa saja untuk menutup...
pause >nul
