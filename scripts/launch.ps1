# ---------------------------------------------------------------------------
# SmartPresensi - Launcher
#
# Menangani elevasi (minta hak Administrator), menjalankan bootstrap, menulis
# log, dan menahan jendela agar tidak menutup mendadak. Dipanggil oleh
# "Kontrol\Mulai SmartPresensi.cmd".
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [switch]$Elevated,
    [Parameter(ValueFromRemainingArguments = $true)]$Passthrough
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Test-IsAdmin {
    try {
        $id = [Security.Principal.WindowsIdentity]::GetCurrent()
        return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch {
        return $false
    }
}

$StateDir = Join-Path $PSScriptRoot 'state'
New-Item -ItemType Directory -Force -Path $StateDir | Out-Null
$Log = Join-Path $StateDir 'bootstrap.log'

# --- Minta elevasi bila belum Administrator ---
if (-not (Test-IsAdmin) -and -not $Elevated) {
    Write-Host 'SmartPresensi membutuhkan hak Administrator.' -ForegroundColor Yellow
    Write-Host 'Membuka jendela Administrator...'
    $argList = "-NoProfile -ExecutionPolicy Bypass -NoExit -File `"$PSCommandPath`" -Elevated"
    if ($Passthrough) { $argList += ' ' + ($Passthrough -join ' ') }
    try {
        Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $argList | Out-Null
    } catch {
        Write-Host "Gagal meningkatkan hak akses: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host 'Klik kanan "Kontrol\Mulai SmartPresensi.cmd" lalu pilih "Run as administrator".'
        Read-Host 'Tekan Enter untuk menutup'
    }
    return
}

Write-Host 'Menjalankan SmartPresensi...' -ForegroundColor Cyan
Write-Host ("Log: {0}" -f $Log)

$bootstrap = Join-Path $PSScriptRoot 'bootstrap.ps1'
$exitCode = 0
try {
    $callArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $bootstrap)
    if ($Passthrough) { $callArgs += $Passthrough }
    & powershell @callArgs
    $exitCode = $LASTEXITCODE
} catch {
    Write-Host "Terjadi kesalahan: $($_.Exception.Message)" -ForegroundColor Red
    $exitCode = 1
}

if ($exitCode -ne 0) {
    Write-Host ''
    Write-Host ("Proses SmartPresensi berhenti (kode {0})." -f $exitCode) -ForegroundColor Yellow
    Write-Host ("Periksa log: {0}" -f $Log) -ForegroundColor Yellow
    if (-not $Elevated) { Read-Host 'Tekan Enter untuk menutup' | Out-Null }
}
