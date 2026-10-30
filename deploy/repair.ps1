<#
.SYNOPSIS
    Perbaikan cepat instalasi SmartPresensi (bundle portable).

.DESCRIPTION
    Menyalakan ulang service, membersihkan cache Laravel, mendaftarkan ulang
    COM zkemkeeper.dll, dan memverifikasi kesehatan web/database/supervisor.

    Jalankan dengan hak Administrator.

.EXAMPLE
    .\repair.ps1

.EXAMPLE
    .\repair.ps1 -RewriteConfig -RestartServices
#>
[CmdletBinding()]
param(
    [string]$BundleRoot,
    [switch]$RewriteConfig,
    [switch]$RestartServices,
    [switch]$SkipCom,
    [switch]$SkipFirewall,
    [switch]$Unattended
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not $BundleRoot) { $BundleRoot = $PSScriptRoot }
$script:Warnings = New-Object System.Collections.Generic.List[string]

function Write-Step([string]$Text) { Write-Host ''; Write-Host "=== $Text ===" -ForegroundColor Cyan }
function Write-Ok([string]$Text) { Write-Host "  [OK] $Text" -ForegroundColor Green }
function Write-Info([string]$Text) { Write-Host "  $Text" }
function Write-Warn2([string]$Text) { Write-Host "  [WARN] $Text" -ForegroundColor Yellow; $script:Warnings.Add($Text) }

function Assert-Admin {
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Skrip ini membutuhkan hak Administrator.'
    }
}

function Test-ServiceExists([string]$Name) {
    return $null -ne (Get-Service -Name $Name -ErrorAction SilentlyContinue)
}

function Get-EnvMap([string]$Path) {
    $map = @{}
    if (-not (Test-Path -LiteralPath $Path)) { return $map }
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*([A-Za-z0-9_]+)\s*=\s*(.*)$') {
            $k = $Matches[1]
            $v = $Matches[2].Trim()
            if ($v.Length -ge 2) {
                $first = $v.Substring(0, 1); $last = $v.Substring($v.Length - 1)
                if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) { $v = $v.Substring(1, $v.Length - 2) }
            }
            $map[$k] = $v
        }
    }
    return $map
}

function Wait-TcpPort {
    param([int]$Port, [int]$TimeoutSec = 60)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.Elapsed.TotalSeconds -lt $TimeoutSec) {
        $client = New-Object System.Net.Sockets.TcpClient
        try {
            $iar = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
            if ($iar.AsyncWaitHandle.WaitOne(3000) -and $client.Connected) { $client.Close(); return $true }
        } catch { } finally { $client.Close() }
        Start-Sleep -Seconds 2
    }
    return $false
}

function Wait-HttpOk {
    param([string]$Url, [int]$TimeoutSec = 60)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.Elapsed.TotalSeconds -lt $TimeoutSec) {
        try {
            $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10 -MaximumRedirection 5
            if ($r.StatusCode -ge 200 -and $r.StatusCode -lt 500) { return [int]$r.StatusCode }
        } catch {
            if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }
        }
        Start-Sleep -Seconds 3
    }
    return 0
}

# ---------------------------------------------------------------------------
Assert-Admin
if (-not (Test-Path -LiteralPath $BundleRoot)) { throw "Folder bundle tidak ditemukan: $BundleRoot" }
$Root = (Resolve-Path -LiteralPath $BundleRoot).Path

$PhpDir = Join-Path $Root 'runtime\php'
$MysqlDir = Join-Path $Root 'runtime\mysql'
$DataDir = Join-Path $Root 'data'
$AppDir = Join-Path $Root 'app'
$ServiceDir = Join-Path $Root 'service'
$ComDir = Join-Path $Root 'com'
$TemplateDir = Join-Path $Root 'templates'
$LogsDir = Join-Path $DataDir 'logs'

$PhpExe = Join-Path $PhpDir 'php.exe'
$MysqlClient = Join-Path $MysqlDir 'bin\mysql.exe'
$HttpdConf = Join-Path $Root 'runtime\apache\conf\httpd.conf'
$MyIni = Join-Path $MysqlDir 'my.ini'
$ServiceExe = Join-Path $ServiceDir 'SmartPresensiSupervisor.exe'

$MysqlService = 'SmartPresensiMySQL'
$ApacheService = 'SmartPresensiApache'
$SupervisorService = 'SmartPresensiSupervisor'

Write-Host 'SmartPresensi - Repair' -ForegroundColor Green

$envMap = Get-EnvMap (Join-Path $AppDir '.env')
$DbName = if ($envMap.ContainsKey('DB_DATABASE')) { $envMap['DB_DATABASE'] } else { 'absensi_siswa' }
$DbUser = if ($envMap.ContainsKey('DB_USERNAME')) { $envMap['DB_USERNAME'] } else { 'root' }
$DbPassword = if ($envMap.ContainsKey('DB_PASSWORD')) { $envMap['DB_PASSWORD'] } else { '' }
if ($envMap.ContainsKey('SERVICE_SUPERVISOR_TOKEN') -and $envMap['SERVICE_SUPERVISOR_TOKEN']) {
    $SupervisorToken = $envMap['SERVICE_SUPERVISOR_TOKEN']
} else {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $SupervisorToken = 'sp_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
}

$DbPort = 3306
if (Test-Path -LiteralPath $MyIni) {
    $m = Select-String -LiteralPath $MyIni -Pattern '^\s*port\s*=\s*(\d+)' | Select-Object -First 1
    if ($m) { $DbPort = [int]$m.Matches[0].Groups[1].Value }
}
$WebPort = 8000
if (Test-Path -LiteralPath $HttpdConf) {
    $m = Select-String -LiteralPath $HttpdConf -Pattern '^\s*Listen\s+(\d+)' | Select-Object -First 1
    if ($m) { $WebPort = [int]$m.Matches[0].Groups[1].Value }
}
Write-Info "DB=$DbName@$DbPort  Web=$WebPort"

foreach ($d in @($LogsDir, (Join-Path $DataDir 'sessions'), (Join-Path $DataDir 'apache'), (Join-Path $DataDir 'mysql-tmp'))) {
    New-Item -ItemType Directory -Force -Path $d | Out-Null
}

# --- Rewrite konfigurasi (opsional) ---
if ($RewriteConfig) {
    Write-Step 'Menulis ulang konfigurasi dari template'
    function New-FromTemplate {
        param([string]$TemplatePath, [string]$Destination, [hashtable]$Tokens)
        $content = Get-Content -LiteralPath $TemplatePath -Raw
        foreach ($key in $Tokens.Keys) { $content = $content.Replace("__${key}__", [string]$Tokens[$key]) }
        $enc = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($Destination, $content, $enc)
    }
    $existingAppUrl = if ($envMap.ContainsKey('APP_URL')) { $envMap['APP_URL'] } else { "http://127.0.0.1:$WebPort" }
    $existingSchool = if ($envMap.ContainsKey('SCHOOL_NAME')) { $envMap['SCHOOL_NAME'] } else { 'Sekolah' }
    $tokens = @{
        ROOT_DIR = $Root; APP_DIR = $AppDir; PHP_DIR = $PhpDir; APACHE_DIR = (Join-Path $Root 'runtime\apache')
        MYSQL_DIR = $MysqlDir; DATA_DIR = $DataDir; WEB_PORT = $WebPort; DB_PORT = $DbPort
        DB_NAME = $DbName; DB_USER = $DbUser; DB_PASSWORD = $DbPassword
        APP_URL = $existingAppUrl
        SCHOOL_NAME = $existingSchool
        SUPERVISOR_TOKEN = $SupervisorToken
    }
    $caTemplate = Join-Path $TemplateDir 'cacert.pem'
    if (Test-Path -LiteralPath $caTemplate) {
        Copy-Item -LiteralPath $caTemplate -Destination (Join-Path $PhpDir 'cacert.pem') -Force
    }
    New-FromTemplate (Join-Path $TemplateDir 'php.ini') (Join-Path $PhpDir 'php.ini') $tokens
    $myTokens = $tokens.Clone()
    $myTokens['DATA_DIR'] = $DataDir -replace '\\', '/'
    $myTokens['MYSQL_DIR'] = $MysqlDir -replace '\\', '/'
    New-FromTemplate (Join-Path $TemplateDir 'my.ini') $MyIni $myTokens
    New-FromTemplate (Join-Path $TemplateDir 'httpd.conf') $HttpdConf $tokens
    New-FromTemplate (Join-Path $TemplateDir 'supervisor_config.ini.template') (Join-Path $ServiceDir 'supervisor_config.ini') $tokens
    Write-Ok 'Konfigurasi ditulis ulang (kecuali .env).'
}

# --- Service ---
Write-Step 'Memeriksa service Windows'
foreach ($svc in @($MysqlService, $ApacheService, $SupervisorService)) {
    if (Test-ServiceExists $svc) {
        # Pastikan pemulihan otomatis (restart-on-crash) terpasang.
        sc.exe failure $svc 'reset=' 86400 'actions=' 'restart/5000/restart/10000/restart/30000' | Out-Null
        if ($RestartServices) {
            try { Restart-Service -Name $svc -Force -ErrorAction Stop; Write-Ok "$svc di-restart." }
            catch {
                Write-Warn2 "$svc gagal restart: $($_.Exception.Message)"
                sc.exe start $svc | Out-Null
            }
        } else {
            $state = (Get-Service -Name $svc).Status
            if ($state -ne 'Running') {
                Write-Info "$svc status=$state; menjalankan..."
                sc.exe start $svc | Out-Null
                Write-Ok "$svc dijalankan."
            } else {
                Write-Ok "$svc sudah berjalan."
            }
        }
    } else {
        Write-Warn2 "Service $svc tidak terdaftar. Jalankan setup.ps1."
    }
}

if (-not (Wait-TcpPort -Port $DbPort -TimeoutSec 60)) { Write-Warn2 "MySQL tidak listen di port $DbPort." }

# --- Cache Laravel ---
Write-Step 'Membersihkan cache Laravel'
if (Test-Path -LiteralPath $PhpExe) {
    Push-Location $AppDir
    try {
        & $PhpExe artisan config:clear | Out-Null
        & $PhpExe artisan cache:clear | Out-Null
        & $PhpExe artisan view:clear | Out-Null
        & $PhpExe artisan config:cache | Out-Null
        Write-Ok 'Cache dibersihkan & konfigurasi di-cache ulang.'
    } finally {
        Pop-Location
    }
} else {
    Write-Warn2 "php.exe tidak ditemukan di $PhpExe"
}

# --- COM ---
Write-Step 'COM fingerprint'
if (-not $SkipCom) {
    $dll = Join-Path $ComDir 'zkemkeeper.dll'
    if (Test-Path -LiteralPath $dll) {
        $regsvr = Join-Path $env:WINDIR 'SysWOW64\regsvr32.exe'
        $p = Start-Process -FilePath $regsvr -ArgumentList '/s', "`"$dll`"" -Wait -PassThru
        if ($p.ExitCode -eq 0) { Write-Ok 'zkemkeeper.dll terdaftar.' } else { Write-Warn2 "regsvr32 exit $($p.ExitCode)." }
    } else {
        Write-Warn2 "zkemkeeper.dll tidak ada di $ComDir."
    }
}

# --- Firewall ---
Write-Step 'Firewall'
if (-not $SkipFirewall) {
    $ruleName = "SmartPresensi Web $WebPort"
    if (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue) {
        Remove-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    }
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $WebPort -Profile Any | Out-Null
    Write-Ok "Port $WebPort diizinkan."
}

# --- Health ---
Write-Step 'Pemeriksaan kesehatan'
$webUrl = "http://127.0.0.1:$WebPort/"
$status = Wait-HttpOk -Url $webUrl -TimeoutSec 90
if ($status -gt 0) { Write-Ok "Web OK ($webUrl, HTTP $status)." } else { Write-Warn2 "Web belum merespons. Cek $LogsDir" }

try {
    $probe = Invoke-WebRequest -Uri 'http://127.0.0.1:4372/api/status' -Headers @{ 'X-Supervisor-Token' = $SupervisorToken } -UseBasicParsing -TimeoutSec 10
    Write-Ok "Supervisor API OK (HTTP $($probe.StatusCode))."
} catch {
    Write-Warn2 'Supervisor API belum merespons.'
}

Write-Host ''
if ($script:Warnings.Count -gt 0) {
    Write-Host 'Repair selesai dengan peringatan:' -ForegroundColor Yellow
    $script:Warnings | ForEach-Object { Write-Host "  - $_" -ForegroundColor Yellow }
} else {
    Write-Host 'Repair selesai tanpa masalah.' -ForegroundColor Green
}

if (-not $Unattended) { Read-Host 'Tekan Enter untuk menutup' | Out-Null }
