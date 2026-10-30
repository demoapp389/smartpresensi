@echo off
setlocal
title Solution X601 Fingerprint Connector
echo ========================================================
echo   Menjalankan Connector Solution X601 (Python)
echo ========================================================
cd /d "%~dp0"

set "PYTHON=python"
where py >nul 2>nul && set "PYTHON=py -3"

%PYTHON% zkconnector.py %*
