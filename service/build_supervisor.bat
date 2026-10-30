@echo off
setlocal
title Build Windows Service Supervisor SmartPresensi
cd /d "%~dp0"

set "CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if not exist "%CSC%" set "CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe"
if not exist "%CSC%" (
    echo [ERROR] csc.exe tidak ditemukan. Pastikan .NET Framework terpasang.
    pause
    exit /b 1
)

set "SPDLL=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\System.ServiceProcess.dll"
if not exist "%SPDLL%" set "SPDLL=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\System.ServiceProcess.dll"

if not exist "%SPDLL%" (
    echo [ERROR] System.ServiceProcess.dll tidak ditemukan.
    pause
    exit /b 1
)

echo Membangun SmartPresensiSupervisor_new.exe ...
"%CSC%" /nologo /target:exe /optimize+ /platform:anycpu /out:"%~dp0SmartPresensiSupervisor_new.exe" /reference:"%SPDLL%" "%~dp0SmartPresensiSupervisor.cs"
if errorlevel 1 (
    echo [ERROR] Build gagal.
    pause
    exit /b 1
)

echo.
echo [OK] Selesai: %~dp0SmartPresensiSupervisor_new.exe
echo     Jalankan update_supervisor.bat (Run as administrator) untuk menerapkan.
pause
