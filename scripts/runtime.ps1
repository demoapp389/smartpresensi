# ---------------------------------------------------------------------------
# SmartPresensi - Runtime (pengelolaan komponen portable di folder runtime\)
#
# Modul fungsi, di-dot-source oleh bootstrap.ps1 dan setup-server.ps1:
#     . (Join-Path $PSScriptRoot 'runtime.ps1')
# ---------------------------------------------------------------------------
Set-StrictMode -Version Latest

function Write-SPTextNoBom {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Content)
    [System.IO.File]::WriteAllText($Path, $Content, (New-Object System.Text.UTF8Encoding($false)))
}

# Nama service Windows (dipakai service.ps1 & bootstrap.ps1).
function Get-SPMysqlServiceName { return 'SmartPresensiMySQL' }
function Get-SPSupervisorServiceName { return 'SmartPresensiSupervisor' }

function Get-SPMysqlRuntimePort { return 3307 }

function Get-BundledMysqlPaths {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $dir = Join-Path $ProjectRoot 'runtime\mysql'
    return [pscustomobject]@{
        Present = Test-Path -LiteralPath (Join-Path $dir 'bin\mysqld.exe')
        Dir     = $dir
        Mysqld  = Join-Path $dir 'bin\mysqld.exe'
        Ini     = Join-Path $dir 'my.ini'
        DataDir = Join-Path $dir 'data'
        Port    = Get-SPMysqlRuntimePort
        Initialized = Test-Path -LiteralPath (Join-Path $dir 'data\mysql')
    }
}

function Update-DotEnv {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][hashtable]$Values
    )
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $lines = New-Object System.Collections.Generic.List[string]
    $remaining = @{}
    foreach ($k in $Values.Keys) { $remaining[$k] = $Values[$k] }
    foreach ($line in Get-Content -LiteralPath $Path) {
        $t = $line.Trim()
        $isKey = $false
        if ($t -ne '' -and -not $t.StartsWith('#')) {
            $i = $t.IndexOf('=')
            if ($i -gt 0) {
                $key = $t.Substring(0, $i).Trim()
                if ($remaining.ContainsKey($key)) {
                    $lines.Add("$key=$($remaining[$key])")
                    $remaining.Remove($key)
                    $isKey = $true
                }
            }
        }
        if (-not $isKey) { $lines.Add($line) }
    }
    foreach ($k in $remaining.Keys) { $lines.Add("$k=$($remaining[$k])") }
    [System.IO.File]::WriteAllText($Path, (($lines -join "`r`n") + "`r`n"), (New-Object System.Text.UTF8Encoding($false)))
}

function Test-TcpPortOpen {
    param([string]$TargetHost = '127.0.0.1', [Parameter(Mandatory)][int]$Port, [int]$TimeoutMs = 1500)
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect($TargetHost, $Port, $null, $null)
        if ($iar.AsyncWaitHandle.WaitOne($TimeoutMs) -and $client.Connected) { return $true }
        return $false
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Get-SPPortOwnerPath {
    param([Parameter(Mandatory)][int]$Port)
    $paths = New-Object System.Collections.Generic.List[string]
    try {
        foreach ($c in @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)) {
            $proc = Get-Process -Id $c.OwningProcess -ErrorAction SilentlyContinue
            if (-not $proc) { continue }
            $path = $null
            try { $path = $proc.Path } catch { }
            if ($path) { $paths.Add($path) } elseif ($paths -notcontains $proc.ProcessName) { $paths.Add($proc.ProcessName) }
        }
    } catch { }
    return @($paths | Select-Object -Unique)
}

function Test-SPPortOwnedByProcess {
    param([Parameter(Mandatory)][int]$Port, [Parameter(Mandatory)][string]$ProcessPath)
    if ([string]::IsNullOrWhiteSpace($ProcessPath)) { return $false }
    $want = $ProcessPath.TrimEnd('\')
    try {
        foreach ($c in @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)) {
            $proc = Get-Process -Id $c.OwningProcess -ErrorAction SilentlyContinue
            if ($proc -and $proc.Path -and ($proc.Path.TrimEnd('\') -ieq $want)) { return $true }
        }
    } catch { }
    return $false
}

function New-SPPhpIni {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $phpDir = Join-Path $ProjectRoot 'runtime\php'
    if (-not (Test-Path -LiteralPath (Join-Path $phpDir 'php.exe'))) { return $false }
    $iniPath = Join-Path $phpDir 'php.ini'
    $extDir = (Join-Path $phpDir 'ext').Replace('\', '/')
    $errLog = (Join-Path $ProjectRoot 'storage\logs\php-error.log').Replace('\', '/')
    $caSource = Join-Path $ProjectRoot 'deploy\templates\cacert.pem'
    $caPath = Join-Path $phpDir 'cacert.pem'
    if (Test-Path -LiteralPath $caSource) {
        Copy-Item -LiteralPath $caSource -Destination $caPath -Force
    }
    $caIni = $caPath.Replace('\', '/')
    $ini = @"
; SmartPresensi - konfigurasi PHP runtime (dibuat otomatis oleh scripts\bootstrap.ps1)
[PHP]
engine = On
short_open_tag = Off
precision = 14
output_buffering = 4096
implicit_flush = Off
serialize_precision = -1
max_execution_time = 300
max_input_time = 120
max_input_vars = 5000
memory_limit = 512M
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = "$errLog"
post_max_size = 32M
upload_max_filesize = 24M
max_file_uploads = 20
default_charset = "UTF-8"
date.timezone = Asia/Jakarta
extension_dir = "$extDir"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=pdo_sqlite
extension=sqlite3
extension=zip

; CA bundle untuk verifikasi TLS (cURL/OpenSSL).
curl.cainfo = "$caIni"
openssl.cafile = "$caIni"

[Session]
session.save_handler = files
session.save_path = "sys_temp"

[opcache]
zend_extension=opcache
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
opcache.save_comments = 1
"@
    Write-SPTextNoBom -Path $iniPath -Content ($ini + "`r`n")
    return $true
}

function Update-SPPythonPth {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $pyDir = Join-Path $ProjectRoot 'runtime\python'
    if (-not (Test-Path -LiteralPath $pyDir)) { return $false }
    $pthFile = Get-ChildItem -LiteralPath $pyDir -Filter 'python*._pth' -File -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $pthFile) { return $false }
    $connector = Join-Path $ProjectRoot 'connector'
    $lines = @(Get-Content -LiteralPath $pthFile.FullName | Where-Object { $_ -notmatch 'connector' })
    $lines += $connector
    Write-SPTextNoBom -Path $pthFile.FullName -Content (($lines -join "`r`n") + "`r`n")
    return $true
}

function New-SPMysqlIni {
    param([Parameter(Mandatory)][string]$ProjectRoot, [int]$Port = 0)
    $mysqlDir = Join-Path $ProjectRoot 'runtime\mysql'
    if (-not (Test-Path -LiteralPath (Join-Path $mysqlDir 'bin\mysqld.exe'))) { return $false }
    if ($Port -le 0) { $Port = Get-SPMysqlRuntimePort }
    $base = $mysqlDir.Replace('\', '/')
    $ini = @"
[mysqld]
basedir="$base"
datadir="$base/data"
port=$Port
bind_address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
max_connections=100

[client]
port=$Port
"@
    Write-SPTextNoBom -Path (Join-Path $mysqlDir 'my.ini') -Content ($ini + "`r`n")
    return $true
}

function Sync-RuntimeConfigs {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $updated = New-Object System.Collections.Generic.List[string]
    try { if (New-SPPhpIni -ProjectRoot $ProjectRoot) { $updated.Add('php.ini') } } catch { }
    try { if (Update-SPPythonPth -ProjectRoot $ProjectRoot) { $updated.Add('python._pth') } } catch { }
    try { if (New-SPMysqlIni -ProjectRoot $ProjectRoot) { $updated.Add('mysql\my.ini') } } catch { }
    return $updated
}

function Start-BundledMysql {
    param([Parameter(Mandatory)][string]$ProjectRoot, [int]$WaitSec = 90)
    $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
    if (-not $p.Present -or -not $p.Initialized) { return $false }
    if (Test-TcpPortOpen -Port $p.Port) {
        if (Test-SPPortOwnedByProcess -Port $p.Port -ProcessPath $p.Mysqld) { return $true }
        Write-Warning ("  Port {0} dipakai proses lain (bukan MySQL proyek ini): {1}" -f $p.Port, ((Get-SPPortOwnerPath -Port $p.Port) -join ', '))
        return $false
    }

    $svcName = Get-SPMysqlServiceName
    if (Get-Service -Name $svcName -ErrorAction SilentlyContinue) {
        # Utamakan service Windows bila sudah terpasang (autostart saat boot).
        Write-Host ("  Menjalankan service MySQL ({0}, port {1})..." -f $svcName, $p.Port)
        try { Start-Service -Name $svcName -ErrorAction Stop } catch { }
    } else {
        if (-not (Test-Path -LiteralPath $p.Ini)) { return $false }
        Write-Host ("  Menjalankan MySQL bawaan (port {0})..." -f $p.Port)
        Start-Process -FilePath $p.Mysqld -ArgumentList "--defaults-file=`"$($p.Ini)`"" -WindowStyle Hidden | Out-Null
    }
    $deadline = (Get-Date).AddSeconds($WaitSec)
    while ((Get-Date) -lt $deadline) {
        if (Test-TcpPortOpen -Port $p.Port) { Write-Host '  MySQL bawaan siap.'; return $true }
        Start-Sleep -Milliseconds 750
    }
    Write-Warning '  MySQL bawaan tidak merespons pada waktunya.'
    return $false
}

function Use-BundledRuntime {
    param([Parameter(Mandatory)][string]$ProjectRoot, [switch]$StartMysql)
    $result = [ordered]@{ Mysql = $null }
    $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
    if ($p.Present -and $p.Initialized) {
        $envFile = Join-Path $ProjectRoot '.env'
        if (Test-Path -LiteralPath $envFile) {
            Update-DotEnv -Path $envFile -Values @{ DB_HOST = '127.0.0.1'; DB_PORT = "$($p.Port)" }
        }
        if ($StartMysql) { Start-BundledMysql -ProjectRoot $ProjectRoot | Out-Null }
        $result.Mysql = [pscustomobject]@{
            Bundled = $true
            Port    = $p.Port
            Running = (Test-SPPortOwnedByProcess -Port $p.Port -ProcessPath $p.Mysqld)
        }
    }
    return [pscustomobject]$result
}
