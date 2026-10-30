# ---------------------------------------------------------------------------
# Membangun bootstrap Web-Based Setup Assistant (Go + Tailwind/Alpine).
# Prasyarat: Go toolchain (https://go.dev/dl/) dan Node (untuk aset wizard).
#
#   powershell -ExecutionPolicy Bypass -File deploy\setup-assistant\build.ps1
#   ... -GoExe C:\path\to\go.exe
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [string]$GoExe,
    [switch]$SkipWeb
)

$ErrorActionPreference = 'Stop'
$dir = $PSScriptRoot
$web = Join-Path $dir 'web'

if (-not $GoExe) { $GoExe = $env:GOEXE }
if (-not $GoExe) {
    $cmd = Get-Command go -ErrorAction SilentlyContinue
    if ($cmd) { $GoExe = $cmd.Source }
}
if (-not $GoExe -or -not (Test-Path -LiteralPath $GoExe)) {
    throw "Go toolchain tidak ditemukan. Pasang Go dari https://go.dev/dl/ atau jalankan dengan -GoExe <path\go.exe>."
}

if (-not $SkipWeb) {
    Write-Host '1/2 Membangun aset wizard (Vite + Tailwind + Alpine)...' -ForegroundColor Cyan
    & npx vite build --config (Join-Path $web 'vite.config.js')
    if ($LASTEXITCODE -ne 0) { throw 'vite build gagal' }
}

Write-Host '2/2 Mengompilasi setup-assistant...' -ForegroundColor Cyan
$env:CGO_ENABLED = '0'
Push-Location $dir
try {
    $out = Join-Path $dir 'setup-assistant.exe'
    & $GoExe build -trimpath -ldflags '-s -w' -o $out .
    if ($LASTEXITCODE -ne 0) { throw 'go build gagal' }
} finally {
    Pop-Location
}

$exe = Join-Path $dir 'setup-assistant.exe'
Write-Host ("Selesai: {0} ({1:N2} MB)" -f $exe, ((Get-Item -LiteralPath $exe).Length / 1MB)) -ForegroundColor Green
