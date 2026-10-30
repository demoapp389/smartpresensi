@echo off
title SmartPresensi Supervisor (Interactive Console Mode)
echo ======================================================================
echo    SMARTPRESENSI SUPERVISOR - BACKGROUND SERVICES
echo ======================================================================
echo  Menjalankan Supervisor dalam mode konsol.
echo  Supervisor akan otomatis menjalankan & memantau:
echo    1. C# Connector Mesin Solution X601 (SDK LAN)
echo    2. Laravel Queue Worker Notifikasi WhatsApp
echo    3. Laravel Scheduler (health check & rekonsiliasi)
echo.
echo  Tekan Ctrl+C untuk menghentikan supervisor dan seluruh sub-prosesnya.
echo ======================================================================
echo.

cd /d "%~dp0"
SmartPresensiSupervisor.exe --console

pause
