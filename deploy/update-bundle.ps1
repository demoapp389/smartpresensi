<#
.SYNOPSIS
    Memperbarui bundle SmartPresensi yang SUDAH ADA secara non-destruktif
    (database di data\ dan app\.env tetap dipertahankan).

.DESCRIPTION
    Menyalin ulang kode aplikasi Laravel dari repo ke <Bundle>\app memakai
    robocopy TANPA /MIR (file di bundle tidak dihapus), lalu menyegarkan
    skrip bundle-level, step engine, supervisor, COM DLL, dan opsional
    membangun ulang setup-assistant.exe.

    Berbeda dengan build_bundle.ps1 yang merakit bundle dari nol (dan
    menghapus folder bundle lama sehingga data\mysql + .env hilang), skrip ini
    aman dijalankan pada instalasi yang sudah berjalan.

.PARAMETER BundleDir
    Folder bundle tujuan. Default: <repo>\dist\SmartPresensi.

.PARAMETER BuildAssistant
    Bangun ulang setup-assistant.exe (web + Go) sebelum menyalinnya.

.PARAMETER GoExe
    Path ke go.exe (diteruskan ke build.ps1) bila -BuildAssistant dipakai.

.PARAMETER StopServices
    Hentikan service Apache & Supervisor sebelum menyalin (butuh admin).

.PARAMETER RunRepair
    Jalankan repair.ps1 -RewriteConfig setelah penyalinan (butuh admin).

.PARAMETER RunMigrations
    Jalankan `php artisan migrate --force` pada bundle setelah penyalinan.

.PARAMETER Sync
    Pakai robocopy /MIR sehingga file di <Bundle>\app yang tidak ada di repo
    dihapus. .env tetap aman (dikecualikan via /XF). Default: tanpa /MIR.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\update-bundle.ps1 -WhatIf

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\update-bundle.ps1 -BuildAssistant -GoExe C:\go\bin\go.exe
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$BundleDir,
    [switch]$BuildAssistant,
    [string]$GoExe,
    [switch]$StopServices,
    [switch]$RunRepair,
    [switch]$RunMigrations,
    [switch]$Sync
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $BundleDir) { $BundleDir = Join-Path $RepoRoot 'dist\SmartPresensi' }
if (-not (Test-Path -LiteralPath $BundleDir)) {
    throw "Bundle tidak ditemukan: $BundleDir. Buat baru dengan build_bundle.ps1."
}
$AppDir = Join-Path $BundleDir 'app'
$Artisan = Join-Path $AppDir 'artisan'
if (-not (Test-Path -LiteralPath $Artisan)) {
    throw "Bukan bundle valid (app\artisan tidak ada): $BundleDir"
}

function Write-Step([string]$Text) {
    Write-Host ""
    Write-Host "=== $Text ===" -ForegroundColor Cyan
}

function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Write-TextNoBom {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string[]]$Lines)
    $text = (($Lines -join "`n") + "`n")
    [System.IO.File]::WriteAllText($Path, $text, (New-Object System.Text.UTF8Encoding($false)))
}

$isAdmin = Test-Admin

Write-Host "SmartPresensi - Perbarui Bundle (non-destruktif)" -ForegroundColor Green
Write-Host "RepoRoot : $RepoRoot"
Write-Host "Bundle   : $BundleDir"
Write-Host "Admin    : $isAdmin"

# --- 0. Opsional: hentikan service yang mengunci file ---
if ($StopServices) {
    Write-Step '0/6 Hentikan service'
    if (-not $isAdmin) { Write-Warning 'Bukan admin; hentikan service secara manual bila terjadi file lock.' }
    foreach ($svc in 'SmartPresensiApache', 'SmartPresensiSupervisor') {
        $s = Get-Service -Name $svc -ErrorAction SilentlyContinue
        if ($s -and $s.Status -ne 'Stopped') {
            if ($PSCmdlet.ShouldProcess($svc, 'Stop-Service')) {
                Stop-Service -Name $svc -Force -ErrorAction SilentlyContinue
                Write-Host "  $svc dihentikan."
            }
        }
    }
}

# --- 1. Sinkronkan kode aplikasi (tanpa menghapus data/.env) ---
Write-Step '1/6 Sinkronkan kode aplikasi'
$excludeDirs = @(
    '.git', 'node_modules', 'tests', 'dist', 'deploy', 'service', 'docs', '.github',
    '.ai', '.agents', 'gentelella-master', 'PHP-soap-baru', 'runtime',
    'connector\_python_artifacts_20261016', 'connector\state', 'connector\__pycache__',
    'storage\logs', 'storage\framework\cache\data', 'storage\framework\sessions',
    'storage\framework\views', 'storage\app\backups', 'storage\app\heartbeats',
    'storage\app\private', 'storage\app\public', 'public\storage'
) | ForEach-Object { Join-Path $RepoRoot $_ }
$excludeFiles = @(
    '.env', '.env.*', '*.env', 'database.sqlite', '*.log',
    'checkpoint.txt', 'offline_buffer*.txt', 'python_path.txt',
    'connector_config*.ini'
)

$rcArgs = @($RepoRoot, $AppDir, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
if ($Sync) { $rcArgs += '/MIR' }
if ($WhatIfPreference) { $rcArgs += '/L' }
$rcArgs += '/XD'; $rcArgs += $excludeDirs
$rcArgs += '/XF'; $rcArgs += $excludeFiles
& robocopy @rcArgs | Out-Null
$rc = $LASTEXITCODE
if ($rc -ge 8) { throw "robocopy gagal dengan kode $rc" }
Write-Host "  robocopy selesai (kode $rc). .env & data\ tidak disentuh."

if (-not $WhatIfPreference) {
    # Runtime development tidak boleh berada di app bundle.
    $appRuntime = Join-Path $AppDir 'runtime'
    if (Test-Path -LiteralPath $appRuntime) {
        Remove-Item -LiteralPath $appRuntime -Recurse -Force
        Write-Host '  app\runtime duplikat dihapus dari bundle.'
    }

    $forbiddenPaths = @(
        (Join-Path $AppDir 'runtime'),
        (Join-Path $AppDir 'storage\app\backups'),
        (Join-Path $AppDir 'storage\app\heartbeats'),
        (Join-Path $AppDir 'storage\app\private'),
        (Join-Path $AppDir 'storage\app\public')
    )
    foreach ($forbidden in $forbiddenPaths) {
        if (Test-Path -LiteralPath $forbidden) {
            throw "Bundle memuat path terlarang: $forbidden"
        }
    }

    # Panduan deployment dipisah per OS. Folder docs/ dikecualikan dari robocopy,
    # jadi salin hanya berkas panduan yang ditampilkan halaman Dokumentasi.
    $guideDir = Join-Path $AppDir 'docs'
    foreach ($guide in 'PANDUAN_DEPLOYMENT_WINDOWS.md', 'PANDUAN_DEPLOYMENT_LINUX.md') {
        $guideSrc = Join-Path $RepoRoot (Join-Path 'docs' $guide)
        if (-not (Test-Path -LiteralPath $guideSrc)) {
            Write-Warning "  panduan tidak ditemukan: docs\$guide"
            continue
        }
        if ($PSCmdlet.ShouldProcess((Join-Path $guideDir $guide), 'Copy')) {
            New-Item -ItemType Directory -Force -Path $guideDir | Out-Null
            Copy-Item -LiteralPath $guideSrc -Destination (Join-Path $guideDir $guide) -Force
        }
    }

    # Hapus cache config lama saja agar kode/konfig baru dipakai. packages.php &
    # services.php DIBIARKAN (hasil package:discover) agar tidak merusak bootstrap.
    $configCache = Join-Path $AppDir 'bootstrap\cache\config.php'
    if (Test-Path -LiteralPath $configCache) { Remove-Item -LiteralPath $configCache -Force -ErrorAction SilentlyContinue }
}

# --- 2. Segarkan skrip bundle-level & step engine ---
Write-Step '2/6 Segarkan skrip bundle-level & step engine'
$bundleScripts = 'setup.ps1', 'setup.cmd', 'repair.ps1', 'backup.ps1', 'restore.ps1', 'start-setup.ps1'
foreach ($f in $bundleScripts) {
    $src = Join-Path $PSScriptRoot $f
    if (Test-Path -LiteralPath $src) {
        if ($PSCmdlet.ShouldProcess((Join-Path $BundleDir $f), 'Copy')) {
            Copy-Item -LiteralPath $src -Destination $BundleDir -Force
        }
    } else { Write-Warning "  Lewati (tidak ada): $f" }
}

$stepsSrc = Join-Path $PSScriptRoot 'steps\setup-steps.ps1'
if (Test-Path -LiteralPath $stepsSrc) {
    $stepsDst = Join-Path $BundleDir 'steps'
    if ($PSCmdlet.ShouldProcess((Join-Path $stepsDst 'setup-steps.ps1'), 'Copy')) {
        New-Item -ItemType Directory -Force -Path $stepsDst | Out-Null
        Copy-Item -LiteralPath $stepsSrc -Destination $stepsDst -Force
    }
} else { Write-Warning '  tahap engine tidak ditemukan: steps\setup-steps.ps1' }

# --- 3. Segarkan service (supervisor) & COM DLL ---
Write-Step '3/6 Segarkan service & COM DLL'
$svcSrc = Join-Path $RepoRoot 'service'
$svcDst = Join-Path $BundleDir 'service'
if (Test-Path -LiteralPath $svcSrc) {
    if ($PSCmdlet.ShouldProcess($svcDst, 'Copy service')) {
        New-Item -ItemType Directory -Force -Path $svcDst | Out-Null
        foreach ($f in 'build_supervisor.bat') {
            $s = Join-Path $svcSrc $f
            if (Test-Path -LiteralPath $s) { Copy-Item -LiteralPath $s -Destination $svcDst -Force }
        }
        $stableExe = Join-Path $svcSrc 'SmartPresensiSupervisor.exe'
        $newExe = Join-Path $svcSrc 'SmartPresensiSupervisor_new.exe'
        $svcSourceExe = $stableExe
        if ((Test-Path -LiteralPath $newExe) -and ((-not (Test-Path -LiteralPath $stableExe)) -or ((Get-Item -LiteralPath $newExe).LastWriteTime -gt (Get-Item -LiteralPath $stableExe).LastWriteTime))) {
            $svcSourceExe = $newExe
        }
        if (Test-Path -LiteralPath $svcSourceExe) {
            try {
                Copy-Item -LiteralPath $svcSourceExe -Destination (Join-Path $svcDst 'SmartPresensiSupervisor.exe') -Force -ErrorAction Stop
            } catch {
                Write-Warning "  SmartPresensiSupervisor.exe terkunci (service berjalan?). Jalankan ulang dengan -StopServices. Detail: $($_.Exception.Message)"
            }
        } else { Write-Warning '  Executable supervisor tidak ditemukan di service\.' }
    }
} else { Write-Warning '  Folder service\ tidak ditemukan.' }

$comSrc = Join-Path $PSScriptRoot 'com\zkemkeeper.dll'
if (Test-Path -LiteralPath $comSrc) {
    $comDst = Join-Path $BundleDir 'com'
    if ($PSCmdlet.ShouldProcess((Join-Path $comDst 'zkemkeeper.dll'), 'Copy')) {
        New-Item -ItemType Directory -Force -Path $comDst | Out-Null
        try {
            Copy-Item -LiteralPath $comSrc -Destination $comDst -Force -ErrorAction Stop
        } catch {
            Write-Warning "  zkemkeeper.dll terkunci (COM/connector berjalan?). Lewati. Detail: $($_.Exception.Message)"
        }
    }
} else { Write-Warning '  zkemkeeper.dll tidak ada di deploy\com\.' }

# --- 4. Setup assistant (opsional rebuild lalu salin) ---
Write-Step '4/6 Setup assistant'
if ($BuildAssistant) {
    $buildScript = Join-Path $PSScriptRoot 'setup-assistant\build.ps1'
    if (-not (Test-Path -LiteralPath $buildScript)) { throw "build.ps1 tidak ditemukan: $buildScript" }
    if ($PSCmdlet.ShouldProcess('setup-assistant.exe', 'Build')) {
        $buildArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $buildScript)
        if ($GoExe) { $buildArgs += @('-GoExe', $GoExe) }
        & powershell @buildArgs
        if ($LASTEXITCODE -ne 0) { throw "build.ps1 keluar dengan kode $LASTEXITCODE" }
    }
}
$assistantExe = Join-Path $PSScriptRoot 'setup-assistant\setup-assistant.exe'
if (Test-Path -LiteralPath $assistantExe) {
    if ($PSCmdlet.ShouldProcess((Join-Path $BundleDir 'setup-assistant.exe'), 'Copy')) {
        try {
            Copy-Item -LiteralPath $assistantExe -Destination (Join-Path $BundleDir 'setup-assistant.exe') -Force -ErrorAction Stop
            Write-Host "  setup-assistant.exe disalin ($((Get-Item $assistantExe).LastWriteTime))."
        } catch {
            Write-Warning "  setup-assistant.exe terkunci (wizard berjalan?). Tutup wizard lalu ulangi. Detail: $($_.Exception.Message)"
        }
    }
} else { Write-Warning '  setup-assistant.exe tidak ada. Jalankan deploy\setup-assistant\build.ps1.' }

# --- 5. Perbarui MANIFEST ---
Write-Step '5/6 Perbarui MANIFEST'
$manifestPath = Join-Path $BundleDir 'MANIFEST.txt'
if ((Test-Path -LiteralPath $manifestPath) -and (-not $WhatIfPreference)) {
    $lines = @(Get-Content -LiteralPath $manifestPath) | Where-Object { $_ -notmatch '^Diperbarui\s*:' }
    $stamp = "Diperbarui    : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') (update-bundle)"
    if ($lines.Count -ge 1) {
        $new = @($lines[0], $stamp) + @($lines | Select-Object -Skip 1)
    } else {
        $new = @($stamp)
    }
    Write-TextNoBom -Path $manifestPath -Lines $new
    Write-Host '  MANIFEST diperbarui.'
} else {
    Write-Host "  [skip] MANIFEST ($manifestPath)"
}

# --- 6. Opsional: repair & migrate ---
Write-Step '6/6 Tindak lanjut (opsional)'
if ($RunRepair) {
    $repair = Join-Path $BundleDir 'repair.ps1'
    if (Test-Path -LiteralPath $repair) {
        if (-not $isAdmin) { Write-Warning 'Bukan admin; repair mungkin gagal.' }
        if ($PSCmdlet.ShouldProcess('repair.ps1', 'Run')) {
            & powershell -NoProfile -ExecutionPolicy Bypass -File $repair -RewriteConfig
            Write-Host "  repair.ps1 kode=$LASTEXITCODE"
        }
    } else { Write-Warning '  repair.ps1 tidak ditemukan di bundle.' }
}
if ($RunMigrations) {
    $php = Join-Path $BundleDir 'runtime\php\php.exe'
    if (Test-Path -LiteralPath $php) {
        if ($PSCmdlet.ShouldProcess('artisan', 'migrate --force')) {
            Push-Location $AppDir
            try { & $php artisan migrate --force; Write-Host "  migrate kode=$LASTEXITCODE" }
            finally { Pop-Location }
        }
    } else { Write-Warning '  runtime\php\php.exe tidak ditemukan.' }
}

Write-Host ""
Write-Host "SELESAI. Bundle diperbarui (data\ & .env dipertahankan): $BundleDir" -ForegroundColor Green
