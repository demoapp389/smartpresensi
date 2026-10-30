@echo off
title SmartPresensi - Mulai
:: Aksi: siapkan dan jalankan layanan  -  Memanggil: scripts\launch.ps1
cd /d "%~dp0.."

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\launch.ps1"

echo.
echo Tekan tombol apa saja untuk menutup...
pause >nul
