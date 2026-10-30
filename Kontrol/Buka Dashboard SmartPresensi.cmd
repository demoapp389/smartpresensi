@echo off
title SmartPresensi - Buka Dashboard
:: Aksi: buka dashboard di browser
cd /d "%~dp0.."

start "" "http://localhost:8000"
