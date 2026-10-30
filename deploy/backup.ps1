<#
.SYNOPSIS
    Backup database SmartPresensi (bundle portable) ke file .sql.

.DESCRIPTION
    Menjalankan mysqldump dari runtime bundle. Hasil disimpan di folder
    backups/ dengan nama bertimestamp. Tidak memerlukan hak Administrator.

.EXAMPLE
    .\backup.ps1

.EXAMPLE
    .\backup.ps1 -OutputDir D:\backup -IncludeFiles
#>
[CmdletBinding()]
param(
    [string]$BundleRoot,
    [string]$OutputDir,
    [string]$DbPassword,
    [switch]$IncludeFiles
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
$DataDir = Join-Path $Root 'data'
$MysqlDump = Join-Path $MysqlDir 'bin\mysqldump.exe'
$MyIni = Join-Path $MysqlDir 'my.ini'

if (-not (Test-Path -LiteralPath $MysqlDump)) { throw "mysqldump tidak ditemukan: $MysqlDump" }
if (-not $OutputDir) { $OutputDir = Join-Path $Root 'backups' }

$envMap = Get-EnvMap (Join-Path $AppDir '.env')
$DbName = if ($envMap.ContainsKey('DB_DATABASE')) { $envMap['DB_DATABASE'] } else { 'absensi_siswa' }
$DbUser = if ($envMap.ContainsKey('DB_USERNAME')) { $envMap['DB_USERNAME'] } else { 'root' }
if (-not $DbPassword) { $DbPassword = if ($envMap.ContainsKey('DB_PASSWORD')) { $envMap['DB_PASSWORD'] } else { '' } }

$DbPort = 3306
if (Test-Path -LiteralPath $MyIni) {
    $m = Select-String -LiteralPath $MyIni -Pattern '^\s*port\s*=\s*(\d+)' | Select-Object -First 1
    if ($m) { $DbPort = [int]$m.Matches[0].Groups[1].Value }
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outFile = Join-Path $OutputDir "smartpresensi-$DbName-$stamp.sql"

Write-Host 'SmartPresensi - Backup' -ForegroundColor Green
Write-Info "Database : $DbName @ 127.0.0.1:$DbPort"
Write-Info "Tujuan   : $outFile"

$optFile = Join-Path $env:TEMP ("sp-myopt-" + [guid]::NewGuid().ToString('N') + ".cnf")
try {
    @(
        '[client]'
        'host=127.0.0.1'
        "port=$DbPort"
        "user=$DbUser"
        "password=$DbPassword"
    ) | Set-Content -LiteralPath $optFile -Encoding ASCII

    Write-Step 'Menjalankan mysqldump'
    & $MysqlDump "--defaults-extra-file=$optFile" '--single-transaction' '--routines' '--triggers' '--events' `
        '--default-character-set=utf8mb4' "--result-file=$outFile" $DbName
    if ($LASTEXITCODE -ne 0) { throw "mysqldump gagal (exit $LASTEXITCODE)." }
} finally {
    Remove-Item -LiteralPath $optFile -Force -ErrorAction SilentlyContinue
}

$size = (Get-Item -LiteralPath $outFile).Length
Write-Ok ("Backup database selesai ({0:N0} KB)." -f ($size / 1KB))

if ($IncludeFiles) {
    Write-Step 'Backup berkas storage (opsional)'
    $storageSrc = Join-Path $AppDir 'storage\app'
    $filesOut = Join-Path $OutputDir "smartpresensi-storage-$stamp"
    if (Test-Path -LiteralPath $storageSrc) {
        robocopy $storageSrc $filesOut /E /NFL /NDL /NJH /NJS /NP | Out-Null
        Write-Ok "Berkas storage disalin ke $filesOut"
    } else {
        Write-Info 'Folder storage\app tidak ada, dilewati.'
    }
}

Write-Host ''
Write-Host "SELESAI. File backup: $outFile" -ForegroundColor Green
