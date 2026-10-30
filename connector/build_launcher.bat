@echo off
setlocal
title Build Launcher Connector Python X601
cd /d "%~dp0"

set "CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if not exist "%CSC%" set "CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe"
if not exist "%CSC%" (
    echo [ERROR] csc.exe tidak ditemukan. Pastikan .NET Framework terpasang.
    pause
    exit /b 1
)

echo Membangun SolutionX601ConnectorV2.exe ...
"%CSC%" /nologo /target:exe /optimize+ /out:"%~dp0SolutionX601ConnectorV2.exe" "%~dp0PythonConnectorLauncher.cs"
if errorlevel 1 (
    echo [ERROR] Build gagal.
    pause
    exit /b 1
)

echo.
echo [OK] Selesai: %~dp0SolutionX601ConnectorV2.exe
pause
