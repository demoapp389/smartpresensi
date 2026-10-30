@echo off
setlocal
title Build Connector Solution X601 (x86)
cd /d "%~dp0"

rem WAJIB x86: COM zkemkeeper.dll terpasang 32-bit (C:\WINDOWS\SysWow64).
set "CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe"
if not exist "%CSC%" (
    echo [ERROR] csc.exe (32-bit .NET Framework) tidak ditemukan.
    pause
    exit /b 1
)

rem Referensi DLR: keyword 'dynamic' memerlukan System.Core + Microsoft.CSharp.
set "FW=%WINDIR%\Microsoft.NET\Framework\v4.0.30319"

echo Membangun SolutionX601Connector.exe (x86) ...
"%CSC%" /nologo /target:exe /optimize+ /platform:x86 ^
    /r:"%FW%\System.Core.dll" /r:"%FW%\Microsoft.CSharp.dll" ^
    /out:"%~dp0SolutionX601Connector.exe" "%~dp0SolutionX601Connector.cs"
if errorlevel 1 (
    echo [ERROR] Build gagal.
    pause
    exit /b 1
)

copy /Y "%~dp0SolutionX601Connector.exe" "%~dp0SolutionX601ConnectorV2.exe" >nul

echo.
echo [OK] %~dp0SolutionX601Connector.exe
echo [OK] %~dp0SolutionX601ConnectorV2.exe
pause
