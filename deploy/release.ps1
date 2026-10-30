<#
.SYNOPSIS
    Membuat rilis SmartPresensi: merakit bundle BERSIH + arsip ZIP siap distribusi.

.DESCRIPTION
    Menjalankan build_bundle.ps1 ke direktori staging (default:
    %TEMP%\smartpresensi-release) sehingga folder LIVE dist\SmartPresensi
    (beserta database data\mysql dan app\.env) TIDAK tersentuh. Hasil
    SmartPresensi.zip disalin ke dist\SmartPresensi.zip lalu diverifikasi
    isinya.

    Verifikasi memastikan:
      - setup-assistant.exe ADA (wizard ikut terbundel)
      - steps\setup-steps.ps1 ADA (mesin langkah ikut terbundel)
      - app\.env TIDAK ADA (fresh-install; dibuat wizard/setup.ps1)
      - data\ hanya berisi folder kosong (tanpa database)

    Gunakan -Download bila cache komponen (%TEMP%\smartpresensi-cache) kosong
    dan ingin mengunduh ulang.

.PARAMETER WebPort
    Port web yang ditulis ke MANIFEST. Default 8080.

.PARAMETER DbPort
    Port DB yang ditulis ke MANIFEST. Default 3307.

.PARAMETER StagingDir
    Direktori perakitan sementara. Default: %TEMP%\smartpresensi-release.

.PARAMETER ZipOut
    Lokasi ZIP hasil. Default: <repo>\dist\SmartPresensi.zip.

.PARAMETER BuildAssistant
    Bangun ulang setup-assistant.exe sebelum merakit bundle.

.PARAMETER GoExe
    Path go.exe (diteruskan ke build.ps1) bila -BuildAssistant.

.PARAMETER Download
    Unduh ulang komponen (tanpa ini, pakai -SkipDownload di build_bundle.ps1).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\release.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\release.ps1 -BuildAssistant -GoExe C:\go\bin\go.exe
#>
[CmdletBinding()]
param(
    [int]$WebPort = 8080,
    [int]$DbPort = 3307,
    [string]$StagingDir,
    [string]$ZipOut,
    [switch]$BuildAssistant,
    [string]$GoExe,
    [switch]$Download
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $StagingDir) { $StagingDir = Join-Path $env:TEMP 'smartpresensi-release' }
if (-not $ZipOut) { $ZipOut = Join-Path $RepoRoot 'dist\SmartPresensi.zip' }

function Write-Step([string]$Text) {
    Write-Host ""
    Write-Host "=== $Text ===" -ForegroundColor Cyan
}

function Get-ZipInfo([string]$Path) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($Path)
    try {
        $names = @($zip.Entries | ForEach-Object { $_.FullName })
        [pscustomobject]@{
            Entries     = $names.Count
            HasExe      = [bool]($names | Where-Object { $_ -eq 'SmartPresensi\setup-assistant.exe' })
            HasSteps    = [bool]($names | Where-Object { $_ -eq 'SmartPresensi\steps\setup-steps.ps1' })
            HasEnv      = [bool]($names | Where-Object { $_ -eq 'SmartPresensi\app\.env' })
            HasRuntime  = [bool]($names | Where-Object { $_ -match '^SmartPresensi\\app\\runtime\\' })
            HasMysqlData = [bool]($names | Where-Object { $_ -match '^SmartPresensi\\app\\runtime\\mysql\\data\\|^SmartPresensi\\runtime\\mysql\\data\\' })
            HasConnectorConfig = [bool]($names | Where-Object { $_ -match '\\connector_config[^\\]*\.ini$' })
            HasSupervisorConfig = [bool]($names | Where-Object { $_ -match '\\supervisor_config\.ini$' })
            DataFileCnt = @($names | Where-Object { $_ -match '^SmartPresensi\\data\\.+[^\\]$' }).Count
        }
    } finally {
        $zip.Dispose()
    }
}

Write-Host "SmartPresensi - Buat Rilis" -ForegroundColor Green
Write-Host "Repo    : $RepoRoot"
Write-Host "Staging : $StagingDir"
Write-Host "ZIP out : $ZipOut"
Write-Host "Port    : web=$WebPort db=$DbPort"

# --- 1. Opsional bangun ulang wizard ---
if ($BuildAssistant) {
    Write-Step '1/4 Bangun setup-assistant.exe'
    $buildScript = Join-Path $PSScriptRoot 'setup-assistant\build.ps1'
    if (-not (Test-Path -LiteralPath $buildScript)) { throw "build.ps1 tidak ditemukan: $buildScript" }
    $buildArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $buildScript)
    if ($GoExe) { $buildArgs += @('-GoExe', $GoExe) }
    & powershell @buildArgs
    if ($LASTEXITCODE -ne 0) { throw "build.ps1 keluar dengan kode $LASTEXITCODE" }
} else {
    Write-Step '1/4 Bangun setup-assistant.exe [skip]'
    Write-Host '  (pakai exe yang ada; tambahkan -BuildAssistant untuk membangun ulang)'
}

# --- 2. Rakit bundle bersih ke staging ---
Write-Step '2/4 Rakit bundle bersih (staging)'
if (Test-Path -LiteralPath $StagingDir) { Remove-Item -LiteralPath $StagingDir -Recurse -Force }
New-Item -ItemType Directory -Force -Path $StagingDir | Out-Null

$buildBundle = Join-Path $PSScriptRoot 'build_bundle.ps1'
if ($Download) {
    & $buildBundle -OutputDir $StagingDir -ZipOnly -WebPort $WebPort -DbPort $DbPort
} else {
    & $buildBundle -OutputDir $StagingDir -ZipOnly -WebPort $WebPort -DbPort $DbPort -SkipDownload
}
if (-not $?) { throw 'build_bundle.ps1 gagal.' }

$stagingZip = Join-Path $StagingDir 'SmartPresensi.zip'
if (-not (Test-Path -LiteralPath $stagingZip)) { throw "ZIP hasil tidak ditemukan: $stagingZip" }

# --- 3. Salin ke dist ---
Write-Step '3/4 Salin ZIP ke dist'
$outDir = Split-Path -Parent $ZipOut
if (-not (Test-Path -LiteralPath $outDir)) { New-Item -ItemType Directory -Force -Path $outDir | Out-Null }
Copy-Item -LiteralPath $stagingZip -Destination $ZipOut -Force
Write-Host ("  {0} ({1:N1} MB)" -f $ZipOut, ((Get-Item $ZipOut).Length / 1MB))

# --- 4. Verifikasi isi ---
Write-Step '4/4 Verifikasi isi ZIP'
$info = Get-ZipInfo $ZipOut
"  entri            : $($info.Entries)"
"  setup-assistant  : $(if ($info.HasExe) { 'ADA' } else { 'TIDAK ADA' })"
"  steps engine     : $(if ($info.HasSteps) { 'ADA' } else { 'TIDAK ADA' })"
"  app\.env         : $(if ($info.HasEnv) { 'ADA (harusnya TIDAK)' } else { 'tidak ada (benar)' })"
"  app\runtime      : $(if ($info.HasRuntime) { 'ADA (harusnya TIDAK)' } else { 'tidak ada (benar)' })"
"  mysql\data       : $(if ($info.HasMysqlData) { 'ADA (harusnya TIDAK)' } else { 'tidak ada (benar)' })"
"  connector config : $(if ($info.HasConnectorConfig) { 'ADA (harusnya TIDAK)' } else { 'tidak ada (benar)' })"
"  supervisor config: $(if ($info.HasSupervisorConfig) { 'ADA (harusnya TIDAK)' } else { 'tidak ada (benar)' })"
"  file di data\    : $($info.DataFileCnt) (harus 0)"

$ok = $true
if (-not $info.HasExe) { Write-Warning 'setup-assistant.exe TIDAK ada di ZIP!'; $ok = $false }
if (-not $info.HasSteps) { Write-Warning 'steps\setup-steps.ps1 TIDAK ada di ZIP!'; $ok = $false }
if ($info.HasEnv) { Write-Warning 'app\.env ikut terbundel (tidak seharusnya).'; $ok = $false }
if ($info.HasRuntime) { Write-Warning 'app\runtime ikut terbundel (duplikat runtime development).'; $ok = $false }
if ($info.HasMysqlData) { Write-Warning 'mysql\data ikut terbundel (data sensitif).'; $ok = $false }
if ($info.HasConnectorConfig) { Write-Warning 'connector_config*.ini ikut terbundel.'; $ok = $false }
if ($info.HasSupervisorConfig) { Write-Warning 'supervisor_config.ini ikut terbundel.'; $ok = $false }
if ($info.DataFileCnt -ne 0) { Write-Warning 'data\ berisi file (tidak boleh pada rilis fresh).'; $ok = $false }

Write-Host ""
if ($ok) { Write-Host "SELESAI. Rilis siap: $ZipOut" -ForegroundColor Green }
else { Write-Host "SELESAI dengan peringatan. Periksa pesan di atas." -ForegroundColor Yellow; exit 1 }
