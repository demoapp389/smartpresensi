<#
.SYNOPSIS
    Restore database SmartPresensi (bundle portable) dari file .sql.

.DESCRIPTION
    Mengimpor file backup ke database menggunakan klien mysql dari runtime
    bundle. Secara default meminta konfirmasi karena akan menimpa data.

    Jalankan dengan hak Administrator (opsional, disarankan saat service
    dihentikan sementara).

.EXAMPLE
    .\restore.ps1 -File ..\backups\smartpresensi-absensi_siswa-20261016-090000.sql

.EXAMPLE
    .\restore.ps1 -File backup.sql -Force -StopServices
#>
[CmdletBinding()]
param(
    [string]$BundleRoot,
    [Parameter(Mandatory)][string]$File,
    [string]$DbPassword,
    [switch]$Force,
    [switch]$StopServices,
    [switch]$Unattended
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not $BundleRoot) { $BundleRoot = $PSScriptRoot }

function Write-Step([string]$Text) { Write-Host ''; Write-Host "=== $Text ===" -ForegroundColor Cyan }
function Write-Ok([string]$Text) { Write-Host "  [OK] $Text" -ForegroundColor Green }
function Write-Info([string]$Text) { Write-Host "  $Text" }

function Get-EnvMap([string]$Path) {
    $map = @{}
    if (-not (Test-Path -LiteralPath $Path)) { return $map }
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*([A-Za-z0-9_]+)\s*=\s*(.*)$') {
            $k = $Matches[1]; $v = $Matches[2].Trim()
            if ($v.Length -ge 2) {
                $first = $v.Substring(0, 1); $last = $v.Substring($v.Length - 1)
                if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) { $v = $v.Substring(1, $v.Length - 2) }
            }
            $map[$k] = $v
        }
    }
    return $map
}

if (-not (Test-Path -LiteralPath $BundleRoot)) { throw "Folder bundle tidak ditemukan: $BundleRoot" }
$Root = (Resolve-Path -LiteralPath $BundleRoot).Path

$MysqlDir = Join-Path $Root 'runtime\mysql'
$AppDir = Join-Path $Root 'app'
$PhpDir = Join-Path $Root 'runtime\php'
$PhpExe = Join-Path $PhpDir 'php.exe'
$MysqlClient = Join-Path $MysqlDir 'bin\mysql.exe'
$MyIni = Join-Path $MysqlDir 'my.ini'
$ServiceDir = Join-Path $Root 'service'

if (-not (Test-Path -LiteralPath $MysqlClient)) { throw "mysql client tidak ditemukan: $MysqlClient" }
if (-not (Test-Path -LiteralPath $File)) { throw "File backup tidak ditemukan: $File" }
$File = (Resolve-Path -LiteralPath $File).Path

$envMap = Get-EnvMap (Join-Path $AppDir '.env')
$DbName = if ($envMap.ContainsKey('DB_DATABASE')) { $envMap['DB_DATABASE'] } else { 'absensi_siswa' }
$DbUser = if ($envMap.ContainsKey('DB_USERNAME')) { $envMap['DB_USERNAME'] } else { 'root' }
if (-not $DbPassword) { $DbPassword = if ($envMap.ContainsKey('DB_PASSWORD')) { $envMap['DB_PASSWORD'] } else { '' } }

$DbPort = 3306
if (Test-Path -LiteralPath $MyIni) {
    $m = Select-String -LiteralPath $MyIni -Pattern '^\s*port\s*=\s*(\d+)' | Select-Object -First 1
    if ($m) { $DbPort = [int]$m.Matches[0].Groups[1].Value }
}

Write-Host 'SmartPresensi - Restore' -ForegroundColor Green
Write-Info "Backup   : $File"
Write-Info "Database : $DbName @ 127.0.0.1:$DbPort"

if (-not $Force) {
    $answer = Read-Host "PERINGATAN: data '$DbName' saat ini akan ditimpa. Lanjutkan? (ketik YA)"
    if ($answer -ne 'YA') { Write-Host 'Dibatalkan.' -ForegroundColor Yellow; return }
}

$supervisorStopped = $false
if ($StopServices) {
    Write-Step 'Menghentikan service sementara'
    foreach ($svc in 'SmartPresensiSupervisor', 'SmartPresensiApache') {
        if (Get-Service -Name $svc -ErrorAction SilentlyContinue) {
            sc.exe stop $svc | Out-Null
            Write-Info "$svc dihentikan."
        }
    }
    $supervisorStopped = $true
}

$optFile = Join-Path $env:TEMP ("sp-myopt-" + [guid]::NewGuid().ToString('N') + ".cnf")
try {
    @(
        '[client]'
        'host=127.0.0.1'
        "port=$DbPort"
        "user=$DbUser"
        "password=$DbPassword"
    ) | Set-Content -LiteralPath $optFile -Encoding ASCII

    Write-Step 'Mengimpor database'
    $cmdLine = "`"$MysqlClient`" --defaults-extra-file=`"$optFile`" --default-character-set=utf8mb4 $DbName < `"$File`""
    & cmd.exe /c $cmdLine
    if ($LASTEXITCODE -ne 0) { throw "Impor gagal (exit $LASTEXITCODE)." }
    Write-Ok 'Impor database selesai.'
} finally {
    Remove-Item -LiteralPath $optFile -Force -ErrorAction SilentlyContinue
}

Write-Step 'Membersihkan cache aplikasi'
if (Test-Path -LiteralPath $PhpExe) {
    Push-Location $AppDir
    try {
        & $PhpExe artisan config:clear | Out-Null
        & $PhpExe artisan cache:clear | Out-Null
        & $PhpExe artisan config:cache | Out-Null
        Write-Ok 'Cache dibersihkan.'
    } finally {
        Pop-Location
    }
}

if ($supervisorStopped) {
    Write-Step 'Menyalakan kembali service'
    foreach ($svc in 'SmartPresensiApache', 'SmartPresensiSupervisor') {
        if (Get-Service -Name $svc -ErrorAction SilentlyContinue) {
            sc.exe start $svc | Out-Null
            Write-Info "$svc dijalankan."
        }
    }
}

Write-Host ''
Write-Host 'RESTORE SELESAI.' -ForegroundColor Green
if (-not $Unattended) { Read-Host 'Tekan Enter untuk menutup' | Out-Null }
