@echo off
title SmartPresensi - Hentikan Sekarang
:: Aksi: hentikan layanan sekarang (autostart tetap aktif)  -  Memanggil: scripts\service-cli.ps1 -Action stop
cd /d "%~dp0.."

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\service-cli.ps1" -Action stop

echo.
echo Tekan tombol apa saja untuk menutup...
pause >nul
