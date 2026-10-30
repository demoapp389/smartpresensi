# ---------------------------------------------------------------------------
# Menjalankan Web-Based Setup Assistant SmartPresensi.
# Membuka browser ke wizard instalasi/diagnostik di 127.0.0.1.
#
#   Klik kanan > Run with PowerShell, atau:
#   powershell -ExecutionPolicy Bypass -File deploy\start-setup.ps1
#
# Opsi:
#   -BundleDir <path>   root bundel (default: folder skrip ini)
#   -Mode <mode>        install | repair | restore (default: install)
#   -Port <n>           port tetap (default: otomatis)
#   -NoElevate          jangan minta hak administrator
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [string]$BundleDir,
    [string]$Mode = 'install',
    [int]$Port = 0,
    [switch]$NoElevate
)

$ErrorActionPreference = 'Stop'

if (-not $BundleDir) { $BundleDir = $PSScriptRoot }
$BundleDir = (Resolve-Path -LiteralPath $BundleDir).Path

$exe = Join-Path $BundleDir 'setup-assistant.exe'
if (-not (Test-Path -LiteralPath $exe)) {
    throw "setup-assistant.exe tidak ditemukan di $BundleDir. Jalankan build_bundle.ps1 lebih dulu."
}

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin -and -not $NoElevate) {
    Write-Host 'Meminta hak administrator (UAC)...' -ForegroundColor Yellow
    $argList = @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', "`"$PSCommandPath`"",
        '-BundleDir', "`"$BundleDir`"",
        '-Mode', $Mode,
        '-NoElevate'
    )
    if ($Port -gt 0) { $argList += @('-Port', $Port) }
    Start-Process -FilePath 'powershell.exe' -ArgumentList $argList -Verb RunAs | Out-Null
    return
}

$assistantArgs = @('-bundle', $BundleDir, '-mode', $Mode)
if ($Port -gt 0) { $assistantArgs += @('-port', $Port) }

& $exe @assistantArgs
