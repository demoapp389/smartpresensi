# ---------------------------------------------------------------------------
# SmartPresensi - Bootstrap (akar proyek portabel)
#
# Memeriksa prasyarat lalu menjalankan sistem. Bila ada komponen yang kurang,
# bootstrap mengarahkan ke wizard (scripts\setup-server.ps1) atau menampilkan
# panduan. Semua path dihitung dari lokasi folder ini, jadi folder proyek bisa
# dikopi ke PC lain tanpa mengubah konfigurasi.
#
#   powershell -ExecutionPolicy Bypass -File scripts\bootstrap.ps1
#   ... -NoStart            (hanya periksa, jangan jalankan)
#   ... -Wizard             (paksa buka wizard setup)
#   ... -SetupDatabase      (buat DB + migrate tanpa bertanya)
#   ... -Console            (jalankan langsung tanpa service, untuk debugging)
#   ... -InstallAutostart   (paksa pasang ulang service autostart)
#   ... -UninstallAutostart (hapus service autostart)
#
# Secara default bootstrap memasang & menjalankan SmartPresensi sebagai Windows
# Service (auto-start saat boot) sehingga tidak perlu menjalankan manual lagi.
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [switch]$NoStart,
    [switch]$Wizard,
    [switch]$SetupDatabase,
    [switch]$Console,
    [switch]$InstallAutostart,
    [switch]$UninstallAutostart
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$LogDir = Join-Path $PSScriptRoot 'state'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
try { Start-Transcript -Path (Join-Path $LogDir 'bootstrap.log') -Force | Out-Null } catch { }

# Muat modul preflight tanpa menjalankan bagian utamanya.
$env:PreflightLibraryOnly = $true
. (Join-Path $PSScriptRoot 'preflight.ps1')
. (Join-Path $PSScriptRoot 'runtime.ps1')
. (Join-Path $PSScriptRoot 'service.ps1')

function Write-TextNoBom {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Content)
    [System.IO.File]::WriteAllText($Path, $Content, (New-Object System.Text.UTF8Encoding($false)))
}

function Sync-SupervisorConfig {
    param(
        [Parameter(Mandatory)][string]$ProjectRoot,
        [Parameter(Mandatory)]$Report,
        [Parameter(Mandatory)][hashtable]$Env
    )
    $svcDir = Join-Path $ProjectRoot 'service'
    $cfgPath = Join-Path $svcDir 'supervisor_config.ini'

    if ($Env.ContainsKey('SERVICE_SUPERVISOR_TOKEN') -and $Env['SERVICE_SUPERVISOR_TOKEN']) {
        $token = $Env['SERVICE_SUPERVISOR_TOKEN']
    } else {
        $bytes = New-Object byte[] 24
        [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        $token = 'sp_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
    }
    $apiPort = if ($Env.ContainsKey('SERVICE_SUPERVISOR_URL') -and $Env['SERVICE_SUPERVISOR_URL'] -match ':(\d+)') { [int]$Matches[1] } else { 4372 }

    $values = [ordered]@{
        project_root          = $ProjectRoot
        php_path              = $Report.Php.Path
        connector_relative_path = 'connector\SolutionX601Connector.exe'
        connector_arguments   = ''
        local_api_port        = $apiPort
        auth_token            = $token
        heartbeat_interval_sec = 5
        queue_list            = 'default,validations,long-running'
        queue_timeout_sec     = 3600
        mysql_port            = $Report.Mysql.Port
        startup_delay_sec     = 10
        mysql_wait_max_sec    = 120
        web_server_enabled    = 'true'
        web_server_port       = $Report.WebPort
    }

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('# Konfigurasi Supervisor SmartPresensi (dibuat otomatis oleh scripts\bootstrap.ps1)')
    foreach ($k in $values.Keys) { $lines.Add("$k=$($values[$k])") }
    Write-TextNoBom -Path $cfgPath -Content (($lines -join "`r`n") + "`r`n")
    return $cfgPath
}

function Set-ConnectorPython {
    param([Parameter(Mandatory)][string]$ProjectRoot, $Python)
    if (-not $Python.Found) { return }
    $file = Join-Path $ProjectRoot 'connector\python_path.txt'
    if (Test-Path -LiteralPath (Split-Path -Parent $file)) {
        Write-TextNoBom -Path $file -Content ($Python.Path + "`r`n")
    }
}

function Invoke-DatabaseSetup {
    param([Parameter(Mandatory)][string]$ProjectRoot, [Parameter(Mandatory)]$Report, [Parameter(Mandatory)][hashtable]$Env)
    if (-not $Report.Php.Found) { return $false }

    $dbName = if ($Env.ContainsKey('DB_DATABASE')) { $Env['DB_DATABASE'] } else { 'absensi_siswa' }
    $php = $Report.Php.Path

    $create = Join-Path $ProjectRoot ("sp-createdb-{0}.php" -f [guid]::NewGuid().ToString('N'))
    $code = @"
<?php
`$host = getenv('SP_DB_HOST'); `$port = getenv('SP_DB_PORT');
`$user = getenv('SP_DB_USER'); `$pass = getenv('SP_DB_PASS'); `$name = getenv('SP_DB_NAME');
try {
    `$pdo = new PDO("mysql:host=`$host;port=`$port", `$user, `$pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    `$pdo->exec("CREATE DATABASE IF NOT EXISTS ``" . `$name . "`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo 'OK';
} catch (Throwable `$e) {
    echo 'ERR: ' . `$e->getMessage();
}
"@
    try {
        Set-Content -LiteralPath $create -Value $code -Encoding UTF8
        $env:SP_DB_HOST = if ($Env.ContainsKey('DB_HOST')) { $Env['DB_HOST'] } else { '127.0.0.1' }
        $env:SP_DB_PORT = [string]$Report.Mysql.Port
        $env:SP_DB_USER = if ($Env.ContainsKey('DB_USERNAME')) { $Env['DB_USERNAME'] } else { 'root' }
        $env:SP_DB_PASS = if ($Env.ContainsKey('DB_PASSWORD')) { $Env['DB_PASSWORD'] } else { '' }
        $env:SP_DB_NAME = $dbName
        $out = (& $php $create 2>&1 | Out-String).Trim()
        if ($out -notmatch '^OK') {
            Write-Host "  [X] Gagal menyiapkan database: $out" -ForegroundColor Red
            return $false
        }
        Write-Host "  [OK] Database '$dbName' siap." -ForegroundColor Green
    } finally {
        try {
            if ($create -and (Test-Path -LiteralPath $create)) { Remove-Item -LiteralPath $create -Force -ErrorAction SilentlyContinue }
        } catch { }
        foreach ($n in 'SP_DB_HOST', 'SP_DB_PORT', 'SP_DB_USER', 'SP_DB_PASS', 'SP_DB_NAME') {
            try {
                if (Test-Path -LiteralPath ("Env:\{0}" -f $n)) { Remove-Item -LiteralPath ("Env:\{0}" -f $n) -ErrorAction SilentlyContinue }
            } catch { }
        }
    }

    Write-Host '  Menjalankan migrasi (php artisan migrate --force)...'
    Push-Location $ProjectRoot
    try {
        & $php artisan migrate --force
        if ($LASTEXITCODE -ne 0) { Write-Host '  [X] Migrasi gagal.' -ForegroundColor Red; return $false }

        Write-Host '  Mengisi akun awal (php artisan db:seed --force)...'
        & $php artisan db:seed --force
        if ($LASTEXITCODE -ne 0) { Write-Host '  [X] Seeder gagal.' -ForegroundColor Red; return $false }
    } finally {
        Pop-Location
    }
    Write-Host '  [OK] Migrasi & akun awal selesai.' -ForegroundColor Green
    return $true
}

function Start-System {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $exe = Join-Path $ProjectRoot 'service\SmartPresensiSupervisor.exe'
    if (-not (Test-Path -LiteralPath $exe)) {
        Write-Host "  [X] SmartPresensiSupervisor.exe tidak ditemukan di service\." -ForegroundColor Red
        return
    }
    Write-Host ''
    Write-Host 'Menjalankan SmartPresensi (Connector + Queue Worker + Web Server)...' -ForegroundColor Cyan
    Write-Host "  Buka browser: http://localhost:$($script:Report.WebPort)" -ForegroundColor Green
    Write-Host '  Tekan Ctrl+C pada jendela ini untuk berhenti.' -ForegroundColor Yellow
    Write-Host ''
    & $exe --console
}

function Show-AutostartStatus {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    foreach ($state in (Get-SmartPresensiServiceState)) {
        $color = if ($state.Status -eq 'Running') { 'Green' } else { 'Yellow' }
        Write-Host ("  - {0,-26} : {1} ({2})" -f $state.Name, $state.Status, $state.StartMode) -ForegroundColor $color
    }
    $webPort = $script:Report.WebPort
    $supPort = $script:Report.SupervisorPort
    $fw = Test-SPFirewallRule -Port $webPort
    $fwLabel = $(if ($fw) { 'firewall OK' } else { 'firewall belum dibuka' })
    Write-Host ("  Aplikasi web (lokal) : http://localhost:{0}" -f $webPort)
    $lanIps = @(Get-SPLanIpList)
    if ($lanIps.Count -eq 0) {
        Write-Host '  Aplikasi web (LAN)   : (tidak ada adaptor LAN aktif)' -ForegroundColor Yellow
    } else {
        foreach ($ip in $lanIps) {
            Write-Host ("  Aplikasi web (LAN)   : http://{0}:{1}  [{2}]" -f $ip, $webPort, $fwLabel)
        }
    }
    Write-Host ("  API supervisor       : http://127.0.0.1:{0}/api/status" -f $supPort)
    Write-Host '  Aplikasi berjalan otomatis di latar belakang (tidak perlu login/buka browser).' -ForegroundColor Cyan
}

# ---------------------------------------------------------------------------
$root = Get-SPProjectRoot

# ---------------------------------------------------------------------------
# Portabilitas: sesuaikan seluruh konfigurasi & service ke lokasi proyek saat ini.
# Saat folder dipindah/dikopi ke PC lain, service lama yang menyimpan path absolut
# otomatis dilepas agar tidak lagi merujuk ke lokasi sebelumnya.
# ---------------------------------------------------------------------------
try { Sync-RuntimeConfigs -ProjectRoot $root | Out-Null } catch { }

if (Test-SPAdmin) {
    try {
        $repaired = @(Repair-SmartPresensiServices -ProjectRoot $root)
        if ($repaired.Count -gt 0) {
            Write-Host ("  [OK] Service yang menunjuk lokasi lama diperbaiki: {0}" -f ($repaired -join ', ')) -ForegroundColor Green
        }
    } catch {
        Write-Warning ("  Perbaikan service gagal: {0}" -f $_.Exception.Message)
    }
} else {
    $stale = @(Get-SPStaleServices -ProjectRoot $root)
    if ($stale.Count -gt 0) {
        Write-Host ("  [!] Service masih menunjuk lokasi lain dan perlu diperbaiki: {0}" -f ($stale -join ', ')) -ForegroundColor Yellow
        Write-Host '      Jalankan lewat "Kontrol\Mulai SmartPresensi.cmd" (Administrator) agar otomatis diperbaiki.' -ForegroundColor Yellow
    }
}

# Pakai komponen portable bila sudah tersedia (sinkronkan .env + jalankan MySQL bawaan).
Use-BundledRuntime -ProjectRoot $root -StartMysql | Out-Null

$script:Report = Invoke-Preflight -ProjectRoot $root
Write-PreflightReport -Report $script:Report

$envMap = Read-DotEnv -Path (Join-Path $root '.env')
Set-ConnectorPython -ProjectRoot $root -Python $script:Report.Python

if ($Wizard -or (-not $script:Report.Ready)) {
    $wizardPath = Join-Path $PSScriptRoot 'setup-server.ps1'
    if (Test-Path -LiteralPath $wizardPath) {
        Write-Host 'Membuka wizard setup di browser...' -ForegroundColor Cyan
        & powershell -NoProfile -ExecutionPolicy Bypass -File $wizardPath

        # Muat ulang status setelah wizard ditutup.
        Use-BundledRuntime -ProjectRoot $root -StartMysql | Out-Null
        $script:Report = Invoke-Preflight -ProjectRoot $root
        Write-PreflightReport -Report $script:Report
        $envMap = Read-DotEnv -Path (Join-Path $root '.env')
        Set-ConnectorPython -ProjectRoot $root -Python $script:Report.Python
    }
}

if (-not $script:Report.Ready) {
    Write-Host 'Sistem belum siap. Yang perlu disiapkan:' -ForegroundColor Yellow
    if (-not $script:Report.Php.Ok) { Write-Host "  - PHP 8.3+ : $($script:Report.Php.Detail)  (mis. pasang Laravel Herd atau letakkan PHP di runtime\php)" }
    if (-not $script:Report.Mysql.Ok) { Write-Host "  - MySQL   : $($script:Report.Mysql.Detail)  (mis. jalankan MySQL service atau letakkan di runtime\mysql)" }
    if (-not $script:Report.Python.Ok) { Write-Host "  - Python  : $($script:Report.Python.Detail)" }
    if (-not $script:Report.App.Ok) { Write-Host "  - Aplikasi: $($script:Report.App.Detail)" }
    Write-Host ''
    Write-Host 'Jalankan lagi "Kontrol\Mulai SmartPresensi.cmd" setelah komponen disiapkan.' -ForegroundColor Cyan
    exit 1
}

# Sinkronkan konfigurasi agar path selalu sesuai lokasi folder saat ini.
$cfgPath = Sync-SupervisorConfig -ProjectRoot $root -Report $script:Report -Env $envMap
Write-Host "Konfigurasi supervisor disinkronkan: $cfgPath" -ForegroundColor Green

# Siapkan database bila belum ada atau belum ada akun.
$needDbSetup = $script:Report.Mysql.Ok -and (($null -eq $script:Report.Mysql.UsersCount) -or ($script:Report.Mysql.UsersCount -eq 0))
if ($needDbSetup) {
    $doSetup = $SetupDatabase
    if (-not $doSetup) {
        $answer = Read-Host 'Database belum siap atau belum ada akun. Buat database, migrasi, dan isi akun awal sekarang? (y/N)'
        $doSetup = ($answer -match '^[yY]')
    }
    if ($doSetup) {
        if (Invoke-DatabaseSetup -ProjectRoot $root -Report $script:Report -Env $envMap) {
            $script:Report = Invoke-Preflight -ProjectRoot $root
        }
    }
}

if ($NoStart) {
    Write-Host 'Pemeriksaan selesai (-NoStart).' -ForegroundColor Cyan
    try { Stop-Transcript | Out-Null } catch { }
    return
}

if ($UninstallAutostart) {
    Write-Host ''
    Write-Host 'Menghapus service autostart SmartPresensi...' -ForegroundColor Cyan
    Uninstall-SmartPresensiAutostart -ProjectRoot $root
    Write-Host '  [OK] Service autostart dihapus.' -ForegroundColor Green
    Show-AutostartStatus -ProjectRoot $root
    try { Stop-Transcript | Out-Null } catch { }
    return
}

if ($Console) {
    Start-System -ProjectRoot $root
    try { Stop-Transcript | Out-Null } catch { }
    return
}

if (-not $script:Report.Admin) {
    Write-Host ''
    Write-Host 'Autostart sebagai Windows Service membutuhkan hak Administrator.' -ForegroundColor Yellow
    Write-Host 'Jalankan lewat "Kontrol\Mulai SmartPresensi.cmd" agar otomatis meminta hak admin.' -ForegroundColor Yellow
    Write-Host 'Untuk sementara menjalankan mode konsol...' -ForegroundColor Yellow
    Start-System -ProjectRoot $root
    try { Stop-Transcript | Out-Null } catch { }
    return
}

Write-Host ''
Write-Host 'Memasang autostart (Windows Service, auto-start saat boot)...' -ForegroundColor Cyan
try {
    Install-SmartPresensiAutostart -ProjectRoot $root -Report $script:Report -ForceMysql:$InstallAutostart | Out-Null
    Write-Host '  [OK] Service terdaftar.' -ForegroundColor Green

    Write-Host '  Mengatur firewall untuk akses LAN...' -ForegroundColor Cyan
    Add-SPFirewallRule -Port $script:Report.WebPort | Out-Null

    Start-Sleep -Seconds 1
    $owners = @(Get-SPPortOwner -Port $script:Report.WebPort)
    if ($owners.Count -gt 0) {
        $ownerList = ($owners | ForEach-Object { "$($_.Name)#$($_.PID)" }) -join ', '
        Write-Host ("  [WARN] Port {0} sedang dipakai proses lain: {1}" -f $script:Report.WebPort, $ownerList) -ForegroundColor Yellow
        Write-Host '         Server web bisa gagal bind. Hentikan proses itu, atau ganti port lewat APP_URL di .env (mis. http://localhost:8123).' -ForegroundColor Yellow
    }

    Write-Host '  Menjalankan service...' -ForegroundColor Cyan
    Start-SmartPresensiServices -ProjectRoot $root | Out-Null

    # Beri kesempatan web server (artisan serve) siap sebelum menampilkan status.
    $deadline = (Get-Date).AddSeconds(40)
    while ((Get-Date) -lt $deadline -and -not (Test-TcpPortOpen -Port $script:Report.WebPort)) { Start-Sleep -Milliseconds 750 }

    Write-Host ''
    Show-AutostartStatus -ProjectRoot $root
    Write-Host ''
    Write-Host 'Selesai. Lain kali cukup nyalakan PC - sistem berjalan otomatis.' -ForegroundColor Green
} catch {
    Write-Host ("  [X] Gagal memasang autostart: {0}" -f $_.Exception.Message) -ForegroundColor Red
    Write-Host '  Coba jalankan "Kontrol\Mulai SmartPresensi.cmd" sebagai Administrator.' -ForegroundColor Yellow
}
try { Stop-Transcript | Out-Null } catch { }
