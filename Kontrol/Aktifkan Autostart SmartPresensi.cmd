@echo off
title SmartPresensi - Aktifkan Autostart
:: Aksi: set Automatic lalu jalankan (jalan otomatis saat boot)  -  Memanggil: scripts\service-cli.ps1 -Action enable
cd /d "%~dp0.."

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\service-cli.ps1" -Action enable

echo.
echo Tekan tombol apa saja untuk menutup...
pause >nul
