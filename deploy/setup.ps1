<#
.SYNOPSIS
    Instalasi bundle portable SmartPresensi di PC baru (tanpa Laragon/XAMPP).

.DESCRIPTION
    Mengisi template konfigurasi, menginisialisasi MySQL portable, mendaftarkan
    service Windows (MySQL, Apache, Supervisor), menyiapkan .env, menjalankan
    migrasi database, mendaftarkan COM zkemkeeper.dll, dan menyalakan firewall.

    Jalankan setup.cmd (Run as administrator) atau:
        powershell -ExecutionPolicy Bypass -File setup.ps1

.EXAMPLE
    .\setup.ps1 -WebPort 8080 -DbPort 3307 -SchoolName "SMA Negeri 1"

.EXAMPLE
    .\setup.ps1 -SkipServices -SkipCom -Unattended
#>
[CmdletBinding()]
param(
    [string]$BundleRoot,
    [int]$WebPort = 8080,
    [int]$DbPort = 3307,
    [string]$DbName = 'absensi_siswa',
    [string]$DbUser = 'root',
    [string]$DbPassword,
    [string]$SchoolName = 'Sekolah',
    [string]$AppUrl,
    [string]$AdminName = 'Administrator Sekolah',
    [string]$AdminEmail,
    [string]$AdminPassword,
    [string]$SupervisorToken,
    [string]$WebhookSecret,
    [string]$ConnectorLocalApiToken,
    [switch]$SkipServices,
    [switch]$SkipCom,
    [switch]$SkipFirewall,
    [switch]$SkipMigrate,
    [switch]$Seed,
    [switch]$Unattended
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Token supervisor wajib unik per instalasi. Dibuat acak bila tidak diberikan lewat parameter.
if (-not $SupervisorToken) {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $SupervisorToken = 'sp_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
}
if (-not $WebhookSecret) {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $WebhookSecret = 'wh_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
}
if (-not $ConnectorLocalApiToken) {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $ConnectorLocalApiToken = 'ct_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
}
if (-not $BundleRoot) { $BundleRoot = $PSScriptRoot }
$script:Warnings = New-Object System.Collections.Generic.List[string]

function Write-Step([string]$Text) {
    Write-Host ''
    Write-Host "=== $Text ===" -ForegroundColor Cyan
}

function Write-Ok([string]$Text) { Write-Host "  [OK] $Text" -ForegroundColor Green }
function Write-Info([string]$Text) { Write-Host "  $Text" }
function Write-Warn2([string]$Text) {
    Write-Host "  [WARN] $Text" -ForegroundColor Yellow
    $script:Warnings.Add($Text)
}

function Assert-Admin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Skrip ini membutuhkan hak Administrator. Jalankan setup.cmd (Run as administrator).'
    }
}

function Test-ServiceExists([string]$Name) {
    return $null -ne (Get-Service -Name $Name -ErrorAction SilentlyContinue)
}

function Set-ServiceRecovery([string]$Name) {
    # Restart otomatis bila service berhenti tak terduga (crash).
    & sc.exe failure $Name 'reset=' 86400 'actions=' 'restart/5000/restart/10000/restart/30000' | Out-Null
}

function Install-WindowsService {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$DisplayName,
        [Parameter(Mandatory)][string]$BinaryPathName
    )
    if (Test-ServiceExists $Name) {
        sc.exe stop $Name | Out-Null
        Start-Sleep -Seconds 2
        sc.exe delete $Name | Out-Null
        Start-Sleep -Seconds 3
    }
    New-Service -Name $Name -BinaryPathName $BinaryPathName -DisplayName $DisplayName -StartupType Automatic | Out-Null
    Set-ServiceRecovery $Name
}

function New-RandomPassword([int]$Length = 20) {
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    $bytes = New-Object byte[] $Length
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $sb = New-Object System.Text.StringBuilder
    foreach ($b in $bytes) { [void]$sb.Append($chars[$b % $chars.Length]) }
    return $sb.ToString()
}

function Get-LanIp {
    try {
        $candidates = [System.Net.Dns]::GetHostAddresses([System.Net.Dns]::GetHostName()) |
            Where-Object { $_.AddressFamily -eq 'InterNetwork' -and -not $_.IsIPv6LinkLocal } |
            Where-Object { $_ -notmatch '^169\.254\.' -and $_ -notmatch '^127\.' }
        $ip = $candidates | Where-Object { $_ -match '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' } | Select-Object -First 1
        if (-not $ip) { $ip = $candidates | Select-Object -First 1 }
        if ($ip) { return $ip.ToString() }
    } catch { }
    return '127.0.0.1'
}

function Write-TextNoBom([string]$Path, [string]$Content) {
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $enc)
}

function New-FromTemplate {
    param(
        [Parameter(Mandatory)][string]$TemplatePath,
        [Parameter(Mandatory)][string]$Destination,
        [Parameter(Mandatory)][hashtable]$Tokens
    )
    if (-not (Test-Path -LiteralPath $TemplatePath)) {
        throw "Template tidak ditemukan: $TemplatePath"
    }
    $content = Get-Content -LiteralPath $TemplatePath -Raw
    foreach ($key in $Tokens.Keys) {
        $content = $content.Replace("__${key}__", [string]$Tokens[$key])
    }
    if ($content -match '__[A-Z_]+__') {
        $left = [regex]::Matches($content, '__[A-Z_]+__') | ForEach-Object { $_.Value } | Select-Object -Unique
        Write-Warn2 "Token belum terisi pada $([IO.Path]::GetFileName($Destination)): $($left -join ', ')"
    }
    $dir = Split-Path -Parent $Destination
    if ($dir -and -not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    Write-TextNoBom -Path $Destination -Content $content
}

function Wait-TcpPort {
    param([string]$TargetHost = '127.0.0.1', [Parameter(Mandatory)][int]$Port, [int]$TimeoutSec = 90)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.Elapsed.TotalSeconds -lt $TimeoutSec) {
        $client = New-Object System.Net.Sockets.TcpClient
        try {
            $iar = $client.BeginConnect($TargetHost, $Port, $null, $null)
            if ($iar.AsyncWaitHandle.WaitOne(3000) -and $client.Connected) { $client.Close(); return $true }
        } catch { } finally { $client.Close() }
        Start-Sleep -Seconds 2
    }
    return $false
}

function Wait-HttpOk {
    param([string]$Url, [int]$TimeoutSec = 90)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.Elapsed.TotalSeconds -lt $TimeoutSec) {
        try {
            $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10 -MaximumRedirection 5
            if ($r.StatusCode -ge 200 -and $r.StatusCode -lt 500) { return $r.StatusCode }
        } catch {
            if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }
        }
        Start-Sleep -Seconds 3
    }
    return 0
}

function Invoke-Php {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$PhpArgs)
    & $PhpExe artisan @PhpArgs
    if ($LASTEXITCODE -ne 0) { Write-Warn2 "php artisan $($PhpArgs -join ' ') keluar dengan kode $LASTEXITCODE" }
}

function Invoke-MysqlRoot {
    param([Parameter(Mandatory)][string]$Sql, [switch]$Passwordless)
    $mysqlArgs = @(
        "--host=127.0.0.1", "--port=$DbPort", "--user=root", "--protocol=TCP",
        "--execute=$Sql"
    )
    if ($Passwordless) { $mysqlArgs += '--skip-password' } else { $mysqlArgs += "--password=$DbPassword" }
    & $MysqlClient @mysqlArgs
    return $LASTEXITCODE
}

# ---------------------------------------------------------------------------
Assert-Admin

if (-not (Test-Path -LiteralPath $BundleRoot)) { throw "Folder bundle tidak ditemukan: $BundleRoot" }
$Root = (Resolve-Path -LiteralPath $BundleRoot).Path

$PhpDir = Join-Path $Root 'runtime\php'
$ApacheDir = Join-Path $Root 'runtime\apache'
$MysqlDir = Join-Path $Root 'runtime\mysql'
$DataDir = Join-Path $Root 'data'
$AppDir = Join-Path $Root 'app'
$ServiceDir = Join-Path $Root 'service'
$ComDir = Join-Path $Root 'com'
$TemplateDir = Join-Path $Root 'templates'
$LogsDir = Join-Path $DataDir 'logs'

$PhpExe = Join-Path $PhpDir 'php.exe'
$ApacheExe = Join-Path $ApacheDir 'bin\httpd.exe'
$Mysqld = Join-Path $MysqlDir 'bin\mysqld.exe'
$MysqlClient = Join-Path $MysqlDir 'bin\mysql.exe'
$HttpdConf = Join-Path $ApacheDir 'conf\httpd.conf'
$MyIni = Join-Path $MysqlDir 'my.ini'
$ServiceExe = Join-Path $ServiceDir 'SmartPresensiSupervisor.exe'

$MysqlService = 'SmartPresensiMySQL'
$ApacheService = 'SmartPresensiApache'
$SupervisorService = 'SmartPresensiSupervisor'

foreach ($required in @($PhpExe, $ApacheExe, $Mysqld, $MysqlClient, $HttpdConf, $MyIni, $ServiceExe)) {
    if (-not (Test-Path -LiteralPath $required)) {
        throw "Komponen bundle tidak lengkap. Tidak ditemukan: $required"
    }
}

if (-not $DbPassword) { $DbPassword = New-RandomPassword }
if (-not $AppUrl) { $AppUrl = "http://$(Get-LanIp):$WebPort" }

$lanIp = Get-LanIp

Write-Host 'SmartPresensi - Instalasi Bundle Portable' -ForegroundColor Green
Write-Host "  Bundle     : $Root"
Write-Host "  Aplikasi   : $AppDir"
Write-Host "  Web port   : $WebPort"
Write-Host "  DB port    : $DbPort (database: $DbName)"
Write-Host "  URL akses  : $AppUrl"
Write-Host "  IP LAN     : $lanIp"

# --- 0. Siapkan folder data & log ---
Write-Step '0/8 Menyiapkan folder data'
foreach ($d in @($LogsDir, (Join-Path $DataDir 'sessions'), (Join-Path $DataDir 'apache'), (Join-Path $DataDir 'mysql-tmp'))) {
    New-Item -ItemType Directory -Force -Path $d | Out-Null
}
foreach ($p in @(
    (Join-Path $AppDir 'storage\logs'),
    (Join-Path $AppDir 'storage\framework\cache\data'),
    (Join-Path $AppDir 'storage\framework\sessions'),
    (Join-Path $AppDir 'storage\framework\views'),
    (Join-Path $AppDir 'bootstrap\cache')
)) {
    New-Item -ItemType Directory -Force -Path $p | Out-Null
}
Write-Ok 'Folder data siap.'

# --- 1. Tulis konfigurasi dari template ---
Write-Step '1/8 Menulis konfigurasi runtime'
$tokens = @{
    ROOT_DIR         = $Root
    APP_DIR          = $AppDir
    PHP_DIR          = $PhpDir
    APACHE_DIR       = $ApacheDir
    MYSQL_DIR        = $MysqlDir
    DATA_DIR         = $DataDir
    WEB_PORT         = $WebPort
    DB_PORT          = $DbPort
    DB_NAME          = $DbName
    DB_USER          = $DbUser
    DB_PASSWORD      = $DbPassword
    APP_URL          = $AppUrl
    SCHOOL_NAME      = $SchoolName
    SUPERVISOR_TOKEN = $SupervisorToken
    WEBHOOK_SECRET   = $WebhookSecret
    CONNECTOR_LOCAL_API_TOKEN = $ConnectorLocalApiToken
}
New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'php.ini')   -Destination (Join-Path $PhpDir 'php.ini')            -Tokens $tokens
$myTokens = $tokens.Clone()
$myTokens['DATA_DIR'] = $DataDir -replace '\\', '/'
$myTokens['MYSQL_DIR'] = $MysqlDir -replace '\\', '/'
New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'my.ini')    -Destination $MyIni                                     -Tokens $myTokens
New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'httpd.conf') -Destination $HttpdConf                               -Tokens $tokens
New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'supervisor_config.ini.template') -Destination (Join-Path $ServiceDir 'supervisor_config.ini') -Tokens $tokens
$envFile = Join-Path $AppDir '.env'
if (-not (Test-Path -LiteralPath $envFile)) {
    New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'env.template') -Destination $envFile -Tokens $tokens
    Write-Ok 'php.ini, my.ini, httpd.conf, supervisor_config.ini, .env tertulis.'
} else {
    Write-Ok 'php.ini, my.ini, httpd.conf, supervisor_config.ini tertulis; .env lama dipertahankan (APP_KEY & WhatsApp utuh).'
}

# --- 2. VC++ Redistributable ---
Write-Step '2/8 Memasang Visual C++ Redistributable'
foreach ($vc in 'vc_redist.x64.exe', 'vc_redist.x86.exe') {
    $vcPath = Join-Path (Join-Path $Root 'runtime\vcredist') $vc
    if (-not (Test-Path -LiteralPath $vcPath)) { Write-Warn2 "$vc tidak ada di bundle (dilewati)."; continue }
    $p = Start-Process -FilePath $vcPath -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    if ($p.ExitCode -in 0, 1638, 3010) { Write-Ok "$vc terpasang (exit $($p.ExitCode))." }
    else { Write-Warn2 "$vc gagal (exit $($p.ExitCode))." }
}

# --- 3. MySQL: inisialisasi datadir ---
Write-Step '3/8 Menyiapkan MySQL'
$mysqlSystem = Join-Path (Join-Path $DataDir 'mysql') 'mysql'
$freshInit = $false
if (-not (Test-Path -LiteralPath $mysqlSystem)) {
    Write-Info 'Menginisialisasi datadir MySQL (initialize-insecure)...'
    & $Mysqld "--defaults-file=$MyIni" '--initialize-insecure'
    if ($LASTEXITCODE -ne 0) { throw "Inisialisasi MySQL gagal (exit $LASTEXITCODE)." }
    $freshInit = $true
    Write-Ok 'Datadir MySQL dibuat.'
} else {
    Write-Info 'Datadir MySQL sudah ada; melewati inisialisasi.'
}

# --- 4. Daftarkan & jalankan service Windows ---
if (-not $SkipServices) {
    Write-Step '4/8 Mendaftarkan service Windows'

    $mysqlBinPath = "`"$Mysqld`" --defaults-file=`"$MyIni`" $MysqlService"
    Install-WindowsService -Name $MysqlService -DisplayName 'SmartPresensi MySQL' -BinaryPathName $mysqlBinPath
    Write-Ok "Service $MysqlService terdaftar."

    sc.exe start $MysqlService | Out-Null
    if (-not (Wait-TcpPort -Port $DbPort -TimeoutSec 120)) { throw "MySQL tidak listen di port $DbPort setelah 120 detik." }
    Write-Ok "MySQL berjalan di port $DbPort."

    if (Test-ServiceExists $ApacheService) { & $ApacheExe -k uninstall -n $ApacheService | Out-Null }
    & $ApacheExe -k install -n $ApacheService -f "$HttpdConf"
    sc.exe config $ApacheService start= auto | Out-Null
    Set-ServiceRecovery $ApacheService
    sc.exe start $ApacheService | Out-Null
    Write-Ok "Service $ApacheService terdaftar & dimulai."

    Install-WindowsService -Name $SupervisorService -DisplayName 'SmartPresensi Supervisor' -BinaryPathName "`"$ServiceExe`""
    sc.exe start $SupervisorService | Out-Null
    Write-Ok "Service $SupervisorService terdaftar & dimulai."
} else {
    Write-Step '4/8 Lewati pendaftaran service (-SkipServices)'
    if (-not (Wait-TcpPort -Port $DbPort -TimeoutSec 15)) {
        Write-Warn2 "MySQL belum berjalan di port $DbPort. Jalankan manual: `"$Mysqld`" --defaults-file=`"$MyIni`""
    }
}

# --- 5. Siapkan database & user ---
Write-Step '5/8 Menyiapkan database'
if ($freshInit) {
    $sql = "ALTER USER 'root'@'localhost' IDENTIFIED BY '$DbPassword';" +
           "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" +
           'FLUSH PRIVILEGES;'
    $code = Invoke-MysqlRoot -Sql $sql -Passwordless
    if ($code -ne 0) { throw 'Gagal menetapkan password root / membuat database.' }
    Write-Ok "Database '$DbName' dibuat dan password root disetel."
} else {
    $code = Invoke-MysqlRoot -Sql "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if ($code -ne 0) {
        Write-Warn2 "Tidak dapat terhubung ke MySQL dengan password saat ini. Pastikan -DbPassword sesuai."
    } else {
        Write-Ok "Database '$DbName' dipastikan ada."
    }
}

# --- 6. Aplikasi Laravel ---
if (-not $SkipMigrate) {
    Write-Step '6/8 Menyiapkan aplikasi (key, cache, migrasi)'
    Push-Location $AppDir
    try {
        Invoke-Php 'config:clear'
        $appKeyLine = Select-String -LiteralPath (Join-Path $AppDir '.env') -Pattern '^\s*APP_KEY\s*=\s*\S' -ErrorAction SilentlyContinue
        if (-not $appKeyLine) { Invoke-Php 'key:generate' '--force' }
        Invoke-Php 'migrate' '--force'
        try { Invoke-Php 'storage:link' } catch { Write-Warn2 'storage:link dilewati.' }
        Invoke-Php 'config:cache'
        if ($AdminEmail -and $AdminPassword) {
            Invoke-Php 'sp:create-admin' "--name=$AdminName" "--email=$AdminEmail" "--password=$AdminPassword"
        } elseif ($Seed) {
            Invoke-Php 'db:seed' '--force'
        }
    } finally {
        Pop-Location
    }
    Write-Ok 'Aplikasi siap.'
} else {
    Write-Step '6/8 Lewati migrasi aplikasi (-SkipMigrate)'
}

# --- 7. COM zkemkeeper + firewall ---
Write-Step '7/8 COM fingerprint & firewall'
if (-not $SkipCom) {
    $dll = Join-Path $ComDir 'zkemkeeper.dll'
    if (Test-Path -LiteralPath $dll) {
        $regsvr = Join-Path $env:WINDIR 'SysWOW64\regsvr32.exe'
        $p = Start-Process -FilePath $regsvr -ArgumentList '/s', "`"$dll`"" -Wait -PassThru
        if ($p.ExitCode -eq 0) { Write-Ok 'zkemkeeper.dll terdaftar (32-bit).' }
        else { Write-Warn2 "regsvr32 keluar dengan kode $($p.ExitCode)." }
    } else {
        Write-Warn2 "zkemkeeper.dll tidak ada di $ComDir. Letakkan file 32-bit di sana lalu jalankan repair.ps1."
    }
} else {
    Write-Warn2 'Pendaftaran COM dilewati (-SkipCom).'
}

if (-not $SkipFirewall) {
    foreach ($rule in @(
        @{ Name = "SmartPresensi Web $WebPort"; Port = $WebPort }
    )) {
        $existing = Get-NetFirewallRule -DisplayName $rule.Name -ErrorAction SilentlyContinue
        if ($existing) { Remove-NetFirewallRule -DisplayName $rule.Name -ErrorAction SilentlyContinue }
        New-NetFirewallRule -DisplayName $rule.Name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $rule.Port -Profile Any | Out-Null
        Write-Ok "Firewall: port $($rule.Port) diizinkan."
    }
} else {
    Write-Warn2 'Konfigurasi firewall dilewati (-SkipFirewall).'
}

# --- 8. Health check ---
Write-Step '8/8 Pemeriksaan kesehatan'
$webUrl = "http://127.0.0.1:$WebPort/"
$status = Wait-HttpOk -Url $webUrl -TimeoutSec 120
if ($status -gt 0) { Write-Ok "Web merespons di $webUrl (HTTP $status)." }
else { Write-Warn2 "Web belum merespons di $webUrl. Cek log: $LogsDir" }

$dbCode = Invoke-MysqlRoot -Sql 'SELECT 1;'
if ($dbCode -eq 0) { Write-Ok 'Koneksi database OK.' } else { Write-Warn2 'Koneksi database gagal.' }

try {
    $probe = Invoke-WebRequest -Uri 'http://127.0.0.1:4372/api/status' -Headers @{ 'X-Supervisor-Token' = $SupervisorToken } -UseBasicParsing -TimeoutSec 10
    Write-Ok "Supervisor API OK (HTTP $($probe.StatusCode))."
} catch {
    Write-Warn2 'Supervisor API belum merespons. Jika baru diinstal, tunggu beberapa detik lalu jalankan repair.ps1.'
}

# ---------------------------------------------------------------------------
Write-Host ''
Write-Host '======================================================================' -ForegroundColor Green
Write-Host '  INSTALASI SELESAI' -ForegroundColor Green
Write-Host '======================================================================' -ForegroundColor Green
Write-Host "  Akses aplikasi : $AppUrl"
if ($AdminEmail) { Write-Host "  Akun admin     : $AdminEmail" }
Write-Host "  Database       : $DbName @ 127.0.0.1:$DbPort"
Write-Host "  Password root  : $DbPassword"
Write-Host "  Supervisor API : http://127.0.0.1:4372 (token: $SupervisorToken)"
Write-Host "  Log            : $LogsDir"
Write-Host '  CATATAN: migrasi data lama (dump+restore) belum dijalankan. Gunakan'
Write-Host '          backup.ps1 dari PC lama lalu restore.ps1 di PC ini.'
if ($script:Warnings.Count -gt 0) {
    Write-Host ''
    Write-Host '  Peringatan:' -ForegroundColor Yellow
    $script:Warnings | ForEach-Object { Write-Host "    - $_" -ForegroundColor Yellow }
}
Write-Host '======================================================================' -ForegroundColor Green

if (-not $Unattended) { Read-Host 'Tekan Enter untuk menutup' | Out-Null }
