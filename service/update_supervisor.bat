@echo off
title Update SmartPresensi Supervisor
echo ======================================================================
echo     UPDATE WINDOWS SERVICE: SMARTPRESENSI SUPERVISOR
echo ======================================================================
echo.

:: Memeriksa hak Administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [ERROR] Skrip ini membutuhkan Hak Administrator!
    echo Silakan klik kanan file ini dan pilih "Run as administrator".
    echo ======================================================================
    pause
    exit /b 1
)

set SERVICE_NAME=SmartPresensiSupervisor
set SERVICE_DIR=%~dp0
set NEW_EXE=%SERVICE_DIR%SmartPresensiSupervisor_new.exe
set OLD_EXE=%SERVICE_DIR%SmartPresensiSupervisor.exe

echo [1/4] Menghentikan service...
sc stop %SERVICE_NAME% >nul 2>&1
timeout /t 5 /nobreak >nul

echo [2/4] Mengganti executable...
copy /Y "%NEW_EXE%" "%OLD_EXE%"

echo [3/4] Menjalankan service kembali...
sc start %SERVICE_NAME%

timeout /t 3 /nobreak >nul

echo [4/4] Memeriksa status...
sc query %SERVICE_NAME% | findstr STATE

echo.
echo ======================================================================
echo   UPDATE SELESAI!
echo   Supervisor kini memiliki fitur:
echo   - Menunggu MySQL siap sebelum menjalankan queue worker
echo   - Startup delay 15 detik agar Laragon sempat loading
echo   - Retry koneksi MySQL maks 120 detik
echo   - Laravel Scheduler otomatis (health check & rekonsiliasi)
echo ======================================================================
echo.
pause
