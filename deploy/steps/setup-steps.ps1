# ---------------------------------------------------------------------------
# SmartPresensi - Step Engine
# Mesin langkah instalasi/diagnostik yang dipanggil oleh Web-Based Setup
# Assistant (Go) maupun skrip CLI (setup.ps1 / repair.ps1).
#
# Kontrak:
#   Input  : -Action <name> [-BundleRoot <dir>] [-PayloadJson <json> | -PayloadFile <path>]
#   Output : satu objek JSON ke stdout
#            { ok, action, steps:[{name,status,message}], data:{}, warnings:[] }
#   Catatan: TIDAK menulis apa pun ke stdout selain JSON akhir.
#
#   powershell -ExecutionPolicy Bypass -File deploy\steps\setup-steps.ps1 `
#       -Action check-ports -BundleRoot dist\SmartPresensi
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Action,
    [string]$BundleRoot,
    [string]$PayloadJson = '',
    [string]$PayloadFile
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$script:Steps = New-Object System.Collections.Generic.List[object]
$script:Warnings = New-Object System.Collections.Generic.List[string]

function Add-Step([string]$Name, [string]$Status, [string]$Message) {
    $script:Steps.Add([ordered]@{ name = $Name; status = $Status; message = $Message })
}
function Add-Warning([string]$Message) { $script:Warnings.Add($Message); Add-Step 'warning' 'warn' $Message }

function Fail([string]$Message) {
    Add-Step $Action 'error' $Message
    [Console]::Out.Write(([ordered]@{ ok = $false; action = $Action; steps = $script:Steps; data = @{}; warnings = $script:Warnings } | ConvertTo-Json -Depth 8))
    exit 1
}

$Payload = @{}
if ($PayloadFile -and (Test-Path -LiteralPath $PayloadFile)) {
    $PayloadJson = Get-Content -LiteralPath $PayloadFile -Raw
}
if ($PayloadJson -and $PayloadJson.Trim()) {
    $parsed = $null
    try { $parsed = $PayloadJson | ConvertFrom-Json } catch { Fail "PayloadJson tidak valid: $($_.Exception.Message)" }
    if ($null -ne $parsed) { $Payload = $parsed }
}

function P([string]$Name, $Default = $null) {
    if ($null -eq $Payload) { return $Default }
    $prop = $Payload.PSObject.Properties[$Name]
    if ($null -ne $prop -and $null -ne $prop.Value) { return $prop.Value }
    return $Default
}

function Assert-Admin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Fail 'Aksi ini membutuhkan hak Administrator. Jalankan ulang start-setup.ps1 sebagai Administrator.'
    }
}

function Test-ServiceExists([string]$Name) { return $null -ne (Get-Service -Name $Name -ErrorAction SilentlyContinue) }

function Set-ServiceRecovery([string]$Name) {
    # Konfigurasi pemulihan: restart otomatis bila service berhenti tak terduga
    # (crash). Tidak memengaruhi 'stop' yang disengaja. 3 percobaan restart
    # (5/10/30 dtk); penghitung reset tiap 24 jam.
    Invoke-Native 'sc.exe' @('failure', $Name, 'reset=', '86400', 'actions=', 'restart/5000/restart/10000/restart/30000') | Out-Null
}

function Install-WindowsService {
    param([Parameter(Mandatory)][string]$Name, [Parameter(Mandatory)][string]$DisplayName, [Parameter(Mandatory)][string]$BinaryPathName)
    if (Test-ServiceExists $Name) {
        Invoke-Native 'sc.exe' @('stop', $Name) | Out-Null
        Start-Sleep -Seconds 2
        Invoke-Native 'sc.exe' @('delete', $Name) | Out-Null
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

function Invoke-Native([string]$FilePath, [string[]]$Arguments) {
    # Menjalankan proses native dan menangkap stdout+stderr tanpa memicu
    # terminasi oleh $ErrorActionPreference='Stop'.
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { return (& $FilePath @Arguments 2>&1) }
    finally { $ErrorActionPreference = $prev }
}

function Invoke-MysqlStdin([string[]]$Arguments, [string]$Sql) {
    # Mengirim SQL lewat stdin (bukan --execute) agar karakter seperti @, tanda
    # kutip, dan titik-koma tidak dirusak penerusan argumen native PowerShell 5.1.
    $tmp = Join-Path $env:TEMP ("sp-sql-" + [guid]::NewGuid().ToString('N') + ".sql")
    Write-TextNoBom -Path $tmp -Content $Sql
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { return (Get-Content -LiteralPath $tmp -Raw | & $MysqlClient @Arguments 2>&1) }
    finally { $ErrorActionPreference = $prev; Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue }
}

function New-FromTemplate {
    param([Parameter(Mandatory)][string]$TemplatePath, [Parameter(Mandatory)][string]$Destination, [Parameter(Mandatory)][hashtable]$Tokens)
    if (-not (Test-Path -LiteralPath $TemplatePath)) { throw "Template tidak ditemukan: $TemplatePath" }
    $content = Get-Content -LiteralPath $TemplatePath -Raw
    foreach ($key in $Tokens.Keys) { $content = $content.Replace("__${key}__", [string]$Tokens[$key]) }
    if ($content -match '__[A-Z_]+__') {
        $left = [regex]::Matches($content, '__[A-Z_]+__') | ForEach-Object { $_.Value } | Select-Object -Unique
        Add-Warning "Token belum terisi pada $([IO.Path]::GetFileName($Destination)): $($left -join ', ')"
    }
    $dir = Split-Path -Parent $Destination
    if ($dir -and -not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    Write-TextNoBom -Path $Destination -Content $content
}

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

function ConvertTo-EnvValue([string]$Value) {
    # Memberi tanda kutip bila nilai mengandung spasi/karakter khusus, agar
    # dotenv (phpdotenv) tidak menolak file .env.
    if ($Value -match '[\s#"'']') {
        $escaped = $Value -replace '\\', '\\' -replace '"', '\"'
        return '"' + $escaped + '"'
    }
    return $Value
}

function Test-AppKeyValid([string]$Key) {
    # APP_KEY Laravel valid bila (setelah prefix base64:) ter-decode menjadi
    # 16 atau 32 byte. Kunci cacat (mis. terpotong) membuat Encrypter melempar
    # "Unsupported cipher or incorrect key length".
    if ([string]::IsNullOrWhiteSpace($Key)) { return $false }
    $material = $Key
    if ($Key.StartsWith('base64:')) { $material = $Key.Substring(7) }
    try { $bytes = [Convert]::FromBase64String($material) } catch { return $false }
    return ($bytes.Length -eq 16 -or $bytes.Length -eq 32)
}

function Update-EnvFile {
    # Menimpa hanya kunci yang diberikan; baris lain (APP_KEY, WA_*, dst.)
    # dibiarkan utuh. Kunci baru ditambahkan di akhir bila belum ada.
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][hashtable]$Values)
    $lines = @(Get-Content -LiteralPath $Path)
    $seen = @{}
    $out = New-Object System.Collections.Generic.List[string]
    foreach ($line in $lines) {
        if ($line -match '^\s*([A-Za-z0-9_]+)\s*=') {
            $k = $Matches[1]
            if ($Values.ContainsKey($k)) { $out.Add("$k=$(ConvertTo-EnvValue ([string]$Values[$k]))"); $seen[$k] = $true; continue }
        }
        $out.Add($line)
    }
    foreach ($k in $Values.Keys) { if (-not $seen.ContainsKey($k)) { $out.Add("$k=$(ConvertTo-EnvValue ([string]$Values[$k]))") } }
    Write-TextNoBom -Path $Path -Content (($out -join "`n") + "`n")
}

function Test-PortOwnedBy {
    # Memastikan listener pada port milik proses tertentu (mis. mysqld bundle),
    # bukan proses lain yang kebetulan memakai port sama.
    param([Parameter(Mandatory)][int]$Port, [Parameter(Mandatory)][string]$ProcessName)
    try {
        $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
        if (-not $conn) { return $false }
        $proc = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
        if (-not $proc) { return $false }
        return ($proc.ProcessName -ieq $ProcessName)
    } catch { return $false }
}

function Stop-StrayBundleProcess {
    # Menghentikan proses yatim yang executable-nya berada di dalam folder bundle
    # (mis. mysqld/httpd yang dulu dijalankan manual lalu tetap memegang datadir/
    # port). Proses dari aplikasi lain (XAMPP/Laragon) TIDAK disentuh karena
    # path-nya berbeda. Membutuhkan hak admin agar Path dapat dibaca.
    param([Parameter(Mandatory)][string]$ProcessName)
    $stopped = New-Object System.Collections.Generic.List[int]
    try {
        foreach ($proc in @(Get-Process -Name $ProcessName -ErrorAction SilentlyContinue)) {
            $path = $null
            try { $path = $proc.Path } catch { $path = $null }
            if ($path -and $path.StartsWith($Root, [System.StringComparison]::OrdinalIgnoreCase)) {
                try { Stop-Process -Id $proc.Id -Force -ErrorAction Stop; $stopped.Add($proc.Id) } catch { }
            }
        }
    } catch { }
    if ($stopped.Count -gt 0) { Start-Sleep -Seconds 2 }
    return , $stopped.ToArray()
}

function Test-FileLocked([Parameter(Mandatory)][string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return $false }
    try {
        $fs = [System.IO.File]::Open($Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
        $fs.Close()
        return $false
    } catch { return $true }
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

function Test-PortFree([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        if ($iar.AsyncWaitHandle.WaitOne(1000) -and $client.Connected) { return $false }
        return $true
    } catch { return $true } finally { $client.Close() }
}

function Wait-HttpOk([string]$Url, [int]$TimeoutSec = 90) {
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

# --- Resolusi root & path bundle --------------------------------------------
if (-not $BundleRoot) { $BundleRoot = Split-Path -Parent $PSScriptRoot }
if (-not (Test-Path -LiteralPath $BundleRoot)) { Fail "Folder bundle tidak ditemukan: $BundleRoot" }
$Root = (Resolve-Path -LiteralPath $BundleRoot).Path

$PhpDir = Join-Path $Root 'runtime\php'
$ApacheDir = Join-Path $Root 'runtime\apache'
$MysqlDir = Join-Path $Root 'runtime\mysql'
$DataDir = Join-Path $Root 'data'
$AppDir = Join-Path $Root 'app'
$ServiceDir = Join-Path $Root 'service'
$ComDir = Join-Path $Root 'com'
$TemplateDir = Join-Path $Root 'templates'
if (-not (Test-Path -LiteralPath $TemplateDir)) { $TemplateDir = Join-Path (Split-Path -Parent $PSScriptRoot) 'templates' }
$LogsDir = Join-Path $DataDir 'logs'

$PhpExe = Join-Path $PhpDir 'php.exe'
$ApacheExe = Join-Path $ApacheDir 'bin\httpd.exe'
$Mysqld = Join-Path $MysqlDir 'bin\mysqld.exe'
$MysqlClient = Join-Path $MysqlDir 'bin\mysql.exe'
$MysqlDump = Join-Path $MysqlDir 'bin\mysqldump.exe'
$HttpdConf = Join-Path $ApacheDir 'conf\httpd.conf'
$MyIni = Join-Path $MysqlDir 'my.ini'
$ServiceExe = Join-Path $ServiceDir 'SmartPresensiSupervisor.exe'
$EnvFile = Join-Path $AppDir '.env'

$MysqlService = 'SmartPresensiMySQL'
$ApacheService = 'SmartPresensiApache'
$SupervisorService = 'SmartPresensiSupervisor'

function Get-ConfigPort([string]$Path, [string]$Pattern) {
    # Membaca port dari konfigurasi runtime yang sudah tertulis. Dipakai agar
    # aksi uji (health/check-ports) mengikuti port bundle yang sebenarnya,
    # bukan default pabrikan, ketika payload tidak menyertakan port.
    if (-not (Test-Path -LiteralPath $Path)) { return 0 }
    $m = Select-String -LiteralPath $Path -Pattern $Pattern -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($m) { return [int]$m.Matches[0].Groups[1].Value }
    return 0
}

$DbPort = [int](P 'dbPort' 0)
if ($DbPort -le 0) { $DbPort = Get-ConfigPort -Path $MyIni -Pattern '^\s*port\s*=\s*(\d+)' }
if ($DbPort -le 0) { $DbPort = Get-ConfigPort -Path $EnvFile -Pattern '^\s*DB_PORT\s*=\s*(\d+)' }
if ($DbPort -le 0) { $DbPort = 3307 }

$WebPort = [int](P 'webPort' 0)
if ($WebPort -le 0) { $WebPort = Get-ConfigPort -Path $HttpdConf -Pattern '^\s*Listen\s+(\d+)' }
if ($WebPort -le 0) { $WebPort = 8080 }

$DbName = [string](P 'dbName' 'absensi_siswa')
$DbUser = [string](P 'dbUser' 'root')
$SupervisorPort = [int](P 'supervisorPort' 4372)

function Get-DbPassword {
    $pw = [string](P 'dbPassword' '')
    if ($pw) { return $pw }
    $envMap = Get-EnvMap $EnvFile
    if ($envMap.ContainsKey('DB_PASSWORD')) { return $envMap['DB_PASSWORD'] }
    return ''
}
function New-SupervisorToken {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return 'sp_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
}
function Get-SupervisorToken {
    $t = [string](P 'supervisorToken' '')
    if ($t) { return $t }
    $envMap = Get-EnvMap $EnvFile
    if ($envMap.ContainsKey('SERVICE_SUPERVISOR_TOKEN') -and $envMap['SERVICE_SUPERVISOR_TOKEN']) { return $envMap['SERVICE_SUPERVISOR_TOKEN'] }
    # Token unik yang dibuat saat instalasi, bukan nilai default pabrikan.
    return (New-SupervisorToken)
}
function New-WebhookSecret {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return 'wh_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
}
function Get-WebhookSecret {
    $envMap = Get-EnvMap $EnvFile
    if ($envMap.ContainsKey('WA_WEBHOOK_SECRET') -and $envMap['WA_WEBHOOK_SECRET']) { return $envMap['WA_WEBHOOK_SECRET'] }
    return (New-WebhookSecret)
}

function New-ConnectorLocalApiToken {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return 'ct_' + (-join ($bytes | ForEach-Object { $_.ToString('x2') }))
}

function Get-ConnectorLocalApiToken {
    $t = [string](P 'connectorLocalApiToken' '')
    if ($t) { return $t }
    $envMap = Get-EnvMap $EnvFile
    if ($envMap.ContainsKey('CONNECTOR_LOCAL_API_TOKEN') -and $envMap['CONNECTOR_LOCAL_API_TOKEN']) { return $envMap['CONNECTOR_LOCAL_API_TOKEN'] }
    return (New-ConnectorLocalApiToken)
}

$script:ResultData = @{}

# ============================================================================
# AKSI
# ============================================================================
switch ($Action) {

    'check-admin' {
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
        $principal = New-Object Security.Principal.WindowsPrincipal($identity)
        $isAdmin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
        Add-Step 'admin' ($(if ($isAdmin) { 'ok' } else { 'warn' })) ($(if ($isAdmin) { 'Hak administrator aktif.' } else { 'Wizard berjalan tanpa hak administrator.' }))
        $script:ResultData = @{ admin = $isAdmin }
    }

    'check-ports' {
        foreach ($pair in @(@{ n = 'web'; p = $WebPort }, @{ n = 'database'; p = $DbPort }, @{ n = 'supervisor'; p = $SupervisorPort })) {
            $free = Test-PortFree $pair.p
            Add-Step ("port-$($pair.n)") ($(if ($free) { 'ok' } else { 'warn' })) ("Port $($pair.p) " + $(if ($free) { 'tersedia.' } else { 'sedang dipakai.' }))
        }
    }

    'check-services' {
        foreach ($svc in @($MysqlService, $ApacheService, $SupervisorService)) {
            if (Test-ServiceExists $svc) {
                $state = (Get-Service -Name $svc).Status
                Add-Step $svc ($(if ($state -eq 'Running') { 'ok' } else { 'warn' })) ("${svc}: $state")
            } else {
                Add-Step $svc 'warn' ("${svc}: belum terpasang")
            }
        }
    }

    'apply-config' {
        foreach ($req in @($PhpExe, $ApacheExe, $Mysqld, $MysqlClient, $HttpdConf, $MyIni, $ServiceExe)) {
            if (-not (Test-Path -LiteralPath $req)) { Fail "Komponen bundle tidak lengkap: $req" }
        }
        $envMap = Get-EnvMap $EnvFile
        $existingEnv = ($envMap.Count -gt 0)
        $dbPasswordProvided = ($null -ne (P 'dbPassword' $null))
        $appUrlProvided = ($null -ne (P 'appUrl' $null))
        $schoolNameProvided = ($null -ne (P 'schoolName' $null))
        $webPortProvided = ($null -ne (P 'webPort' $null))

        $dbPassword = [string](P 'dbPassword' '')
        if (-not $dbPassword -and $envMap.ContainsKey('DB_PASSWORD')) { $dbPassword = [string]$envMap['DB_PASSWORD'] }
        if (-not $dbPassword) { $dbPassword = New-RandomPassword }

        $schoolName = [string](P 'schoolName' '')
        if (-not $schoolName -and $envMap.ContainsKey('SCHOOL_NAME')) { $schoolName = [string]$envMap['SCHOOL_NAME'] }
        if (-not $schoolName) { $schoolName = 'Sekolah' }

        $appUrl = [string](P 'appUrl' '')
        if (-not $appUrl -and $envMap.ContainsKey('APP_URL')) { $appUrl = [string]$envMap['APP_URL'] }
        if (-not $appUrl) { $appUrl = "http://$(Get-LanIp):$WebPort" }
        $supervisorToken = Get-SupervisorToken
        $webhookSecret = Get-WebhookSecret
        $connectorLocalApiToken = Get-ConnectorLocalApiToken

        foreach ($d in @($LogsDir, (Join-Path $DataDir 'sessions'), (Join-Path $DataDir 'apache'), (Join-Path $DataDir 'mysql-tmp'))) {
            New-Item -ItemType Directory -Force -Path $d | Out-Null
        }
        foreach ($p in @(
            (Join-Path $AppDir 'storage\logs'),
            (Join-Path $AppDir 'storage\framework\cache\data'),
            (Join-Path $AppDir 'storage\framework\sessions'),
            (Join-Path $AppDir 'storage\framework\views'),
            (Join-Path $AppDir 'bootstrap\cache')
        )) { New-Item -ItemType Directory -Force -Path $p | Out-Null }

        $tokens = @{
            ROOT_DIR = $Root; APP_DIR = $AppDir; PHP_DIR = $PhpDir; APACHE_DIR = $ApacheDir
            MYSQL_DIR = $MysqlDir; DATA_DIR = $DataDir; WEB_PORT = $WebPort; DB_PORT = $DbPort
            DB_NAME = $DbName; DB_USER = $DbUser; DB_PASSWORD = $dbPassword; APP_URL = $appUrl
            SCHOOL_NAME = $schoolName; SUPERVISOR_TOKEN = $supervisorToken
            WEBHOOK_SECRET = $webhookSecret; CONNECTOR_LOCAL_API_TOKEN = $connectorLocalApiToken
        }
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'php.ini') -Destination (Join-Path $PhpDir 'php.ini') -Tokens $tokens
        $myTokens = $tokens.Clone()
        $myTokens['DATA_DIR'] = $DataDir -replace '\\', '/'
        $myTokens['MYSQL_DIR'] = $MysqlDir -replace '\\', '/'
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'my.ini') -Destination $MyIni -Tokens $myTokens
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'httpd.conf') -Destination $HttpdConf -Tokens $tokens
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'supervisor_config.ini.template') -Destination (Join-Path $ServiceDir 'supervisor_config.ini') -Tokens $tokens

        if ($existingEnv) {
            # Pertahankan .env (APP_KEY, WA_*, mail, dsb.). Hanya perbarui kunci
            # yang eksplisit diminta atau memang harus mengikuti port baru.
            $updates = @{
                DB_HOST     = '127.0.0.1'
                DB_PORT     = $DbPort
                DB_DATABASE = $DbName
                DB_USERNAME = $DbUser
            }
            if ($dbPasswordProvided) { $updates['DB_PASSWORD'] = $dbPassword }
            if ($schoolNameProvided) { $updates['SCHOOL_NAME'] = $schoolName }
            $updates['WA_WEBHOOK_SECRET'] = $webhookSecret
            $updates['CONNECTOR_LOCAL_API_TOKEN'] = $connectorLocalApiToken
            if ($appUrlProvided) {
                $updates['APP_URL'] = $appUrl
            } elseif ($webPortProvided) {
                $hostPart = '127.0.0.1'
                if ($envMap.ContainsKey('APP_URL')) {
                    try { $hostPart = ([System.Uri]$envMap['APP_URL']).Host } catch { $hostPart = '127.0.0.1' }
                } else { $hostPart = Get-LanIp }
                $updates['APP_URL'] = "http://${hostPart}:$WebPort"
            }
            Update-EnvFile -Path $EnvFile -Values $updates
            Add-Step 'apply-config' 'ok' 'Konfigurasi runtime ditulis ulang; .env lama dipertahankan (APP_KEY & WhatsApp utuh).'
        } else {
            New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'env.template') -Destination $EnvFile -Tokens $tokens
            Add-Step 'apply-config' 'ok' 'php.ini, my.ini, httpd.conf, supervisor_config.ini, .env tertulis (baru).'
        }
        $script:ResultData = @{ dbPassword = $dbPassword; supervisorToken = $supervisorToken; appUrl = $appUrl }
    }

    'install-vc' {
        Assert-Admin
        foreach ($vc in 'vc_redist.x64.exe', 'vc_redist.x86.exe') {
            $vcPath = Join-Path (Join-Path $Root 'runtime\vcredist') $vc
            if (-not (Test-Path -LiteralPath $vcPath)) { Add-Warning "$vc tidak ada di bundle (dilewati)."; continue }
            $p = Start-Process -FilePath $vcPath -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
            if ($p.ExitCode -in 0, 1638, 3010) {
                $note = if ($p.ExitCode -eq 1638) { 'sudah terpasang (versi lain), dilewati' }
                        elseif ($p.ExitCode -eq 3010) { 'terpasang; restart diperlukan' }
                        else { 'terpasang' }
                Add-Step $vc 'ok' "$vc $note (exit $($p.ExitCode))."
            } else { Add-Warning "$vc gagal (exit $($p.ExitCode))." }
        }
    }

    'init-mysql' {
        Assert-Admin
        $mysqlSystem = Join-Path (Join-Path $DataDir 'mysql') 'mysql'
        if (-not (Test-Path -LiteralPath $mysqlSystem)) {
            Invoke-Native $Mysqld @("--defaults-file=$MyIni", '--initialize-insecure') | Out-Null
            if ($LASTEXITCODE -ne 0) { Fail "Inisialisasi MySQL gagal (exit $LASTEXITCODE)." }
            Add-Step 'init-mysql' 'ok' 'Datadir MySQL dibuat.'
        } else {
            Add-Step 'init-mysql' 'ok' 'Datadir MySQL sudah ada; dilewati.'
        }
    }

    'install-mysql-service' {
        Assert-Admin
        $stray = Stop-StrayBundleProcess -ProcessName 'mysqld'
        if ($stray.Count) { Add-Step 'mysql-stray' 'ok' "Proses mysqld yatim milik bundle dihentikan (PID: $($stray -join ', '))." }
        $ibdata = Join-Path (Join-Path $DataDir 'mysql') 'ibdata1'
        $waited = 0
        while ((Test-FileLocked $ibdata) -and $waited -lt 10) { Start-Sleep -Seconds 1; $waited++ }
        if (Test-FileLocked $ibdata) {
            Fail "Datadir MySQL ($ibdata) masih terkunci proses lain. Hentikan proses tersebut (Task Manager) atau restart komputer, lalu ulangi."
        }
        Install-WindowsService -Name $MysqlService -DisplayName 'SmartPresensi MySQL' -BinaryPathName "`"$Mysqld`" --defaults-file=`"$MyIni`" $MysqlService"
        Invoke-Native 'sc.exe' @('start', $MysqlService) | Out-Null
        if (-not (Wait-TcpPort -Port $DbPort -TimeoutSec 120)) { Fail "MySQL tidak listen di port $DbPort." }
        if (-not (Test-PortOwnedBy -Port $DbPort -ProcessName 'mysqld')) {
            Add-Warning "Port $DbPort dipakai proses lain (bukan mysqld bundle), kemungkinan XAMPP/Laragon. Hentikan layanan MySQL lain atau pilih port database lain (mis. 3307), lalu jalankan 'Perbaiki & Daftar Ulang'."
        } else {
            Add-Step 'install-mysql-service' 'ok' "Service $MysqlService berjalan di port $DbPort."
        }
    }

    'setup-database' {
        Assert-Admin
        $dbPassword = Get-DbPassword
        $mysqlArgs = @("--host=127.0.0.1", "--port=$DbPort", "--user=root", "--protocol=TCP")
        # Deteksi apakah root masih tanpa password (datadir baru diinisialisasi).
        Invoke-Native $MysqlClient ($mysqlArgs + @('--skip-password', '--execute=SELECT 1;')) | Out-Null
        $passwordless = ($LASTEXITCODE -eq 0)
        if ($passwordless) {
            $sql = "ALTER USER 'root'@'localhost' IDENTIFIED BY '$dbPassword';" +
                   "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;FLUSH PRIVILEGES;"
            Invoke-MysqlStdin ($mysqlArgs + @('--skip-password')) $sql | Out-Null
            if ($LASTEXITCODE -ne 0) { Fail 'Gagal menetapkan password root / membuat database.' }
            Add-Step 'setup-database' 'ok' "Database '$DbName' dibuat dan password root disetel."
        } else {
            $sql = "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            Invoke-MysqlStdin ($mysqlArgs + @("--password=$dbPassword")) $sql | Out-Null
            if ($LASTEXITCODE -ne 0) { Add-Warning "Tidak dapat terhubung ke MySQL; periksa kata sandi database." }
            else { Add-Step 'setup-database' 'ok' "Database '$DbName' dipastikan ada." }
        }
    }

    'reset-db-password' {
        Assert-Admin
        $dbPassword = Get-DbPassword
        if (-not $dbPassword) { Fail 'Kata sandi database tidak tersedia (isi di Konfigurasi atau buat app\.env lebih dulu).' }

        $ibdata = Join-Path (Join-Path $DataDir 'mysql') 'ibdata1'
        $tmpSql = Join-Path $env:TEMP ("sp-reset-" + [guid]::NewGuid().ToString('N') + ".sql")
        $proc = $null
        $errorMsg = $null

        try {
            if (Test-ServiceExists $MysqlService) {
                Invoke-Native 'sc.exe' @('stop', $MysqlService) | Out-Null
                $swStop = [System.Diagnostics.Stopwatch]::StartNew()
                while ((Get-Service -Name $MysqlService).Status -ne 'Stopped' -and $swStop.Elapsed.TotalSeconds -lt 60) { Start-Sleep -Seconds 1 }
            }
            Stop-StrayBundleProcess -ProcessName 'mysqld' | Out-Null
            $waited = 0
            while ((Test-FileLocked $ibdata) -and $waited -lt 15) { Start-Sleep -Seconds 1; $waited++ }
            if (Test-FileLocked $ibdata) { throw "Datadir MySQL ($ibdata) masih terkunci proses lain. Hentikan proses tersebut lalu ulangi." }

            Write-TextNoBom -Path $tmpSql -Content "ALTER USER 'root'@'localhost' IDENTIFIED BY '$dbPassword';FLUSH PRIVILEGES;`n"
            $proc = Start-Process -FilePath $Mysqld -ArgumentList @("--defaults-file=$MyIni", "--init-file=$tmpSql") -PassThru

            # Tunggu bukan hanya port terbuka, tetapi juga otentikasi berhasil.
            # --init-file dieksekusi sesaat SETELAH server mulai listen, jadi
            # koneksi pertama bisa ditolak sampai kata sandi benar-benar disetel.
            $connOk = $false
            $lastErr = ''
            $swReady = [System.Diagnostics.Stopwatch]::StartNew()
            while ($swReady.Elapsed.TotalSeconds -lt 30) {
                $resp = Invoke-Native $MysqlClient @("--host=127.0.0.1", "--port=$DbPort", "--user=root", "--protocol=TCP", "--password=$dbPassword", "--execute=SELECT 1;")
                if ($LASTEXITCODE -eq 0) { $connOk = $true; break }
                $lastErr = ("$resp" -replace '\s+', ' ').Trim()
                if ($proc.HasExited) { break }
                Start-Sleep -Seconds 2
            }
            if (-not $connOk) { throw "Reset kata sandi gagal: tidak dapat terhubung ke MySQL sementara dengan kata sandi baru (exit=$LASTEXITCODE). Detail: $lastErr" }

            # Matikan server sementara dengan bersih (client SHUTDOWN, lalu mysqladmin, lalu force).
            Invoke-Native $MysqlClient @("--host=127.0.0.1", "--port=$DbPort", "--user=root", "--protocol=TCP", "--password=$dbPassword", "--execute=SHUTDOWN;") | Out-Null
            $swShut = [System.Diagnostics.Stopwatch]::StartNew()
            while (-not $proc.HasExited -and $swShut.Elapsed.TotalSeconds -lt 30) { Start-Sleep -Seconds 1 }
            if (-not $proc.HasExited) {
                $mysqladmin = Join-Path $MysqlDir 'bin\mysqladmin.exe'
                if (Test-Path -LiteralPath $mysqladmin) {
                    Invoke-Native $mysqladmin @("--host=127.0.0.1", "--port=$DbPort", "--user=root", "--protocol=TCP", "--password=$dbPassword", "shutdown") | Out-Null
                    $swShut2 = [System.Diagnostics.Stopwatch]::StartNew()
                    while (-not $proc.HasExited -and $swShut2.Elapsed.TotalSeconds -lt 30) { Start-Sleep -Seconds 1 }
                }
            }
        } catch {
            $errorMsg = $_.Exception.Message
        } finally {
            # SELALU: bunuh server sementara, bersihkan file, lalu nyalakan service
            # kembali (best-effort) agar kegagalan tidak meninggalkan MySQL mati.
            if ($proc -and -not $proc.HasExited) { try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { } }
            Stop-StrayBundleProcess -ProcessName 'mysqld' | Out-Null
            Copy-Item -LiteralPath $tmpSql -Destination (Join-Path $env:TEMP 'sp-reset-last.sql') -Force -ErrorAction SilentlyContinue
            Remove-Item -LiteralPath $tmpSql -Force -ErrorAction SilentlyContinue
            $waited2 = 0
            while ((Test-FileLocked $ibdata) -and $waited2 -lt 20) { Start-Sleep -Seconds 1; $waited2++ }
            if (-not (Test-FileLocked $ibdata)) {
                Install-WindowsService -Name $MysqlService -DisplayName 'SmartPresensi MySQL' -BinaryPathName "`"$Mysqld`" --defaults-file=`"$MyIni`" $MysqlService"
                Invoke-Native 'sc.exe' @('start', $MysqlService) | Out-Null
                Wait-TcpPort -Port $DbPort -TimeoutSec 120 | Out-Null
            }
        }

        if ($errorMsg) { Fail $errorMsg }
        if (-not (Wait-TcpPort -Port $DbPort -TimeoutSec 60)) { Fail "MySQL tidak listen di port $DbPort setelah reset." }
        Add-Step 'reset-db-password' 'ok' 'Kata sandi root MySQL disetel ulang sesuai app\.env; layanan berjalan.'
    }

    'install-apache-service' {
        Assert-Admin
        $stray = Stop-StrayBundleProcess -ProcessName 'httpd'
        if ($stray.Count) { Add-Step 'apache-stray' 'ok' "Proses httpd yatim milik bundle dihentikan (PID: $($stray -join ', '))." }
        if (Test-ServiceExists $ApacheService) { Invoke-Native $ApacheExe @('-k', 'uninstall', '-n', $ApacheService) | Out-Null }
        Invoke-Native $ApacheExe @('-k', 'install', '-n', $ApacheService, '-f', $HttpdConf) | Out-Null
        Invoke-Native 'sc.exe' @('config', $ApacheService, 'start=', 'auto') | Out-Null
        Set-ServiceRecovery $ApacheService
        Invoke-Native 'sc.exe' @('start', $ApacheService) | Out-Null
        Add-Step 'install-apache-service' 'ok' "Service $ApacheService terdaftar & dimulai."
    }

    'install-supervisor-service' {
        Assert-Admin
        Install-WindowsService -Name $SupervisorService -DisplayName 'SmartPresensi Supervisor' -BinaryPathName "`"$ServiceExe`""
        Invoke-Native 'sc.exe' @('start', $SupervisorService) | Out-Null
        Add-Step 'install-supervisor-service' 'ok' "Service $SupervisorService terdaftar & dimulai."
    }

    'setup-app' {
        Assert-Admin
        $envMap = Get-EnvMap $EnvFile
        $hasAppKey = Test-AppKeyValid ([string]$envMap['APP_KEY'])
        if (-not $hasAppKey -and $envMap.ContainsKey('APP_KEY') -and -not [string]::IsNullOrWhiteSpace([string]$envMap['APP_KEY'])) {
            Add-Warning 'APP_KEY di .env tidak valid (panjang salah); akan dibuat ulang.'
        }
        if (-not $hasAppKey) {
            # Bila .env kosong tetapi cache konfigurasi punya kunci VALID, pakai
            # kunci cache agar data terenkripsi/sesi lama tetap valid.
            $cacheConfig = Join-Path $AppDir 'bootstrap\cache\config.php'
            if (Test-Path -LiteralPath $cacheConfig) {
                $m = Select-String -LiteralPath $cacheConfig -Pattern "'key'\s*=>\s*'([^']+)'" | Select-Object -First 1
                if ($m -and (Test-AppKeyValid $m.Matches[0].Groups[1].Value)) {
                    Update-EnvFile -Path $EnvFile -Values @{ APP_KEY = $m.Matches[0].Groups[1].Value }
                    $hasAppKey = $true
                    Add-Step 'setup-app-key' 'ok' 'APP_KEY diisi dari cache konfigurasi (data lama tetap aman).'
                }
            }
        }
        $cmds = New-Object System.Collections.Generic.List[object]
        $cmds.Add(@('config:clear'))
        if (-not $hasAppKey) { $cmds.Add(@('key:generate', '--force')) }
        $cmds.Add(@('migrate', '--force'))
        $cmds.Add(@('storage:link'))
        $cmds.Add(@('config:cache'))
        Push-Location $AppDir
        try {
            foreach ($cmd in $cmds) {
                $out = Invoke-Native $PhpExe (@('artisan') + $cmd)
                if ($LASTEXITCODE -ne 0) {
                    Add-Warning "php artisan $($cmd -join ' ') keluar dengan kode ${LASTEXITCODE}: $(($out | Out-String).Trim())"
                }
            }
            if ([bool](P 'seed' $false)) { Invoke-Native $PhpExe @('artisan', 'db:seed', '--force') | Out-Null }
        } finally { Pop-Location }
        if ($hasAppKey) { Add-Step 'setup-app' 'ok' 'Aplikasi siap (APP_KEY dipertahankan, migrasi, cache).' }
        else { Add-Step 'setup-app' 'ok' 'Aplikasi siap (APP_KEY baru dibuat, migrasi, cache).' }
    }

    'create-admin' {
        Assert-Admin
        $adminName = [string](P 'adminName' '')
        if (-not $adminName) { $adminName = 'Administrator Sekolah' }
        $adminEmail = [string](P 'adminEmail' '')
        $adminPassword = [string](P 'adminPassword' '')
        if (-not $adminEmail -or -not $adminPassword) {
            Add-Warning 'Akun administrator tidak dibuat karena email/kata sandi belum diisi di langkah Konfigurasi.'
        } else {
            Push-Location $AppDir
            try {
                $out = Invoke-Native $PhpExe @('artisan', 'sp:create-admin', "--name=$adminName", "--email=$adminEmail", "--password=$adminPassword")
                if ($LASTEXITCODE -eq 0) {
                    Add-Step 'create-admin' 'ok' "Akun administrator '$adminEmail' dibuat."
                    $script:ResultData = @{ adminEmail = $adminEmail }
                } else {
                    $detail = ("$out" -replace '\s+', ' ').Trim()
                    Add-Warning "Gagal membuat akun administrator: $detail"
                }
            } finally { Pop-Location }
        }
    }

    'register-com' {
        Assert-Admin
        $dll = Join-Path $ComDir 'zkemkeeper.dll'
        if (Test-Path -LiteralPath $dll) {
            $regsvr = Join-Path $env:WINDIR 'SysWOW64\regsvr32.exe'
            $p = Start-Process -FilePath $regsvr -ArgumentList '/s', "`"$dll`"" -Wait -PassThru
            if ($p.ExitCode -eq 0) { Add-Step 'register-com' 'ok' 'zkemkeeper.dll terdaftar (32-bit).' }
            else { Add-Warning "regsvr32 keluar dengan kode $($p.ExitCode)." }
        } else {
            Add-Warning "zkemkeeper.dll tidak ada di $ComDir."
        }
    }

    'configure-firewall' {
        Assert-Admin
        $name = "SmartPresensi Web $WebPort"
        $existing = Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue
        if ($existing) { Remove-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue }
        New-NetFirewallRule -DisplayName $name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $WebPort -Profile Any | Out-Null
        Add-Step 'configure-firewall' 'ok' "Firewall: port $WebPort diizinkan."
    }

    'repair' {
        Assert-Admin
        $envMap = Get-EnvMap $EnvFile
        $dbPassword = [string](P 'dbPassword' '')
        if (-not $dbPassword -and $envMap.ContainsKey('DB_PASSWORD')) { $dbPassword = [string]$envMap['DB_PASSWORD'] }
        $schoolName = [string](P 'schoolName' '')
        if (-not $schoolName -and $envMap.ContainsKey('SCHOOL_NAME')) { $schoolName = [string]$envMap['SCHOOL_NAME'] }
        if (-not $schoolName) { $schoolName = 'Sekolah' }
        $appUrl = [string](P 'appUrl' '')
        if (-not $appUrl -and $envMap.ContainsKey('APP_URL')) { $appUrl = [string]$envMap['APP_URL'] }
        if (-not $appUrl) { $appUrl = "http://$(Get-LanIp):$WebPort" }
        $supervisorToken = Get-SupervisorToken
        $webhookSecret = Get-WebhookSecret
        $connectorLocalApiToken = Get-ConnectorLocalApiToken
        if (-not ($envMap.ContainsKey('WA_WEBHOOK_SECRET') -and $envMap['WA_WEBHOOK_SECRET'])) {
            Update-EnvFile -Path $EnvFile -Values @{ WA_WEBHOOK_SECRET = $webhookSecret }
            Add-Step 'repair-webhook-secret' 'ok' 'WA_WEBHOOK_SECRET ditambahkan ke .env.'
        }
        if (-not ($envMap.ContainsKey('CONNECTOR_LOCAL_API_TOKEN') -and $envMap['CONNECTOR_LOCAL_API_TOKEN'])) {
            Update-EnvFile -Path $EnvFile -Values @{ CONNECTOR_LOCAL_API_TOKEN = $connectorLocalApiToken }
            Add-Step 'repair-connector-token' 'ok' 'CONNECTOR_LOCAL_API_TOKEN ditambahkan ke .env.'
        }

        foreach ($d in @($LogsDir, (Join-Path $DataDir 'sessions'), (Join-Path $DataDir 'apache'), (Join-Path $DataDir 'mysql-tmp'))) {
            New-Item -ItemType Directory -Force -Path $d | Out-Null
        }

        $tokens = @{
            ROOT_DIR = $Root; APP_DIR = $AppDir; PHP_DIR = $PhpDir; APACHE_DIR = $ApacheDir
            MYSQL_DIR = $MysqlDir; DATA_DIR = $DataDir; WEB_PORT = $WebPort; DB_PORT = $DbPort
            DB_NAME = $DbName; DB_USER = $DbUser; DB_PASSWORD = $dbPassword; APP_URL = $appUrl
            SCHOOL_NAME = $schoolName; SUPERVISOR_TOKEN = $supervisorToken
            WEBHOOK_SECRET = $webhookSecret; CONNECTOR_LOCAL_API_TOKEN = $connectorLocalApiToken
        }
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'php.ini') -Destination (Join-Path $PhpDir 'php.ini') -Tokens $tokens
        $myTokens = $tokens.Clone()
        $myTokens['DATA_DIR'] = $DataDir -replace '\\', '/'
        $myTokens['MYSQL_DIR'] = $MysqlDir -replace '\\', '/'
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'my.ini') -Destination $MyIni -Tokens $myTokens
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'httpd.conf') -Destination $HttpdConf -Tokens $tokens
        New-FromTemplate -TemplatePath (Join-Path $TemplateDir 'supervisor_config.ini.template') -Destination (Join-Path $ServiceDir 'supervisor_config.ini') -Tokens $tokens
        Add-Step 'repair-config' 'ok' 'Konfigurasi runtime ditulis ulang ke path saat ini (.env dipertahankan).'

        $stray = Stop-StrayBundleProcess -ProcessName 'mysqld'
        if ($stray.Count) { Add-Step 'repair-mysql-stray' 'ok' "Proses mysqld yatim milik bundle dihentikan (PID: $($stray -join ', '))." }
        $ibdata = Join-Path (Join-Path $DataDir 'mysql') 'ibdata1'
        $waited = 0
        while ((Test-FileLocked $ibdata) -and $waited -lt 10) { Start-Sleep -Seconds 1; $waited++ }
        if (Test-FileLocked $ibdata) {
            Add-Warning "Datadir MySQL ($ibdata) masih terkunci proses lain; layanan MySQL kemungkinan gagal start."
        }
        Install-WindowsService -Name $MysqlService -DisplayName 'SmartPresensi MySQL' -BinaryPathName "`"$Mysqld`" --defaults-file=`"$MyIni`" $MysqlService"
        Invoke-Native 'sc.exe' @('start', $MysqlService) | Out-Null
        if (Wait-TcpPort -Port $DbPort -TimeoutSec 120) {
            if (Test-PortOwnedBy -Port $DbPort -ProcessName 'mysqld') {
                Add-Step 'repair-mysql' 'ok' "Service $MysqlService menunjuk path baru & berjalan di port $DbPort."
            } else {
                Add-Warning "Port $DbPort dipakai proses lain (bukan mysqld bundle)."
            }
        } else { Add-Warning "MySQL belum listen di port $DbPort." }

        $strayHttpd = Stop-StrayBundleProcess -ProcessName 'httpd'
        if ($strayHttpd.Count) { Add-Step 'repair-apache-stray' 'ok' "Proses httpd yatim milik bundle dihentikan (PID: $($strayHttpd -join ', '))." }
        if (Test-ServiceExists $ApacheService) { Invoke-Native $ApacheExe @('-k', 'uninstall', '-n', $ApacheService) | Out-Null }
        Invoke-Native $ApacheExe @('-k', 'install', '-n', $ApacheService, '-f', $HttpdConf) | Out-Null
        Invoke-Native 'sc.exe' @('config', $ApacheService, 'start=', 'auto') | Out-Null
        Set-ServiceRecovery $ApacheService
        Invoke-Native 'sc.exe' @('start', $ApacheService) | Out-Null
        Add-Step 'repair-apache' 'ok' "Service $ApacheService didaftarkan ulang (path baru)."

        Install-WindowsService -Name $SupervisorService -DisplayName 'SmartPresensi Supervisor' -BinaryPathName "`"$ServiceExe`""
        Invoke-Native 'sc.exe' @('start', $SupervisorService) | Out-Null
        Add-Step 'repair-supervisor' 'ok' "Service $SupervisorService didaftarkan ulang (path baru)."

        $dll = Join-Path $ComDir 'zkemkeeper.dll'
        if (Test-Path -LiteralPath $dll) {
            $regsvr = Join-Path $env:WINDIR 'SysWOW64\regsvr32.exe'
            $p = Start-Process -FilePath $regsvr -ArgumentList '/s', "`"$dll`"" -Wait -PassThru
            if ($p.ExitCode -eq 0) { Add-Step 'repair-com' 'ok' 'zkemkeeper.dll terdaftar (32-bit).' }
            else { Add-Warning "regsvr32 keluar dengan kode $($p.ExitCode)." }
        } else { Add-Warning "zkemkeeper.dll tidak ada di $ComDir." }

        $fwName = "SmartPresensi Web $WebPort"
        if (Get-NetFirewallRule -DisplayName $fwName -ErrorAction SilentlyContinue) { Remove-NetFirewallRule -DisplayName $fwName -ErrorAction SilentlyContinue }
        New-NetFirewallRule -DisplayName $fwName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $WebPort -Profile Any | Out-Null
        Add-Step 'repair-firewall' 'ok' "Firewall: port $WebPort diizinkan."

        $script:ResultData = @{ dbPassword = $dbPassword; supervisorToken = $supervisorToken; appUrl = $appUrl }
    }

    'health' {
        $webUrl = "http://127.0.0.1:$WebPort/"
        $timeoutSec = [int](P 'timeoutSec' 30)
        $status = Wait-HttpOk -Url $webUrl -TimeoutSec $timeoutSec
        if ($status -gt 0) {
            Add-Step 'web' 'ok' "Web $webUrl -> HTTP $status"
        } else {
            Add-Step 'web' 'warn' "Web $webUrl tidak merespons dalam $timeoutSec detik (periksa service Apache & port)."
        }

        $dbPassword = Get-DbPassword
        if ($dbPassword) {
            Invoke-Native $MysqlClient @("--host=127.0.0.1", "--port=$DbPort", "--user=root", "--protocol=TCP", "--password=$dbPassword", "--execute=SELECT 1;") | Out-Null
            $dbOk = ($LASTEXITCODE -eq 0)
            if ($dbOk) {
                Add-Step 'database' 'ok' "Koneksi database OK (127.0.0.1:$DbPort)."
            } else {
                Add-Step 'database' 'warn' "Koneksi database 127.0.0.1:$DbPort gagal (periksa service MySQL, port, dan DB_PASSWORD di .env)."
            }
        } else {
            Add-Step 'database' 'warn' 'Password database belum tersedia (.env belum dibuat).'
        }

        $token = Get-SupervisorToken
        try {
            $probe = Invoke-WebRequest -Uri "http://127.0.0.1:$SupervisorPort/api/status" -Headers @{ 'X-Supervisor-Token' = $token } -UseBasicParsing -TimeoutSec 10
            Add-Step 'supervisor' 'ok' "Supervisor API OK (HTTP $($probe.StatusCode))."
        } catch {
            Add-Step 'supervisor' 'warn' 'Supervisor API belum merespons.'
        }
    }

    'diagnostic' {
        $id = [Security.Principal.WindowsIdentity]::GetCurrent()
        $principal = New-Object Security.Principal.WindowsPrincipal($id)
        $script:ResultData = @{
            generatedAt = (Get-Date).ToString('s')
            os = [System.Environment]::OSVersion.VersionString
            hostname = [System.Net.Dns]::GetHostName()
            admin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
            bundleRoot = $Root
            components = @{}
        }
        foreach ($c in @(@{ n = 'php'; p = $PhpExe; a = @('-v') }, @{ n = 'apache'; p = $ApacheExe; a = @('-v') }, @{ n = 'mysql'; p = $Mysqld; a = @('--version') })) {
            if (Test-Path -LiteralPath $c.p) {
                $v = (Invoke-Native $c.p @($c.a) | Select-Object -First 1)
                $script:ResultData.components[$c.n] = @{ present = $true; version = "$v" }
            } else {
                $script:ResultData.components[$c.n] = @{ present = $false }
            }
        }
        foreach ($svc in @($MysqlService, $ApacheService, $SupervisorService)) {
            $script:ResultData.$svc = if (Test-ServiceExists $svc) { (Get-Service -Name $svc).Status.ToString() } else { 'absent' }
        }
        Add-Step 'diagnostic' 'ok' 'Laporan diagnostik dikumpulkan (tanpa data rahasia).'
    }

    'backup' {
        $script = Join-Path $Root 'backup.ps1'
        if (-not (Test-Path -LiteralPath $script)) { Fail 'backup.ps1 tidak ditemukan di bundle.' }
        $out = Invoke-Native 'powershell' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-BundleRoot', $Root) | Out-String
        $code = $LASTEXITCODE
        if ($code -eq 0) { Add-Step 'backup' 'ok' 'Backup selesai.' }
        else { Add-Step 'backup' 'warn' "Backup keluar dengan kode $code." }
        $script:ResultData = @{ output = ($out -split "`n" | Select-Object -Last 5) -join "`n" }
    }

    'restore' {
        $script = Join-Path $Root 'restore.ps1'
        if (-not (Test-Path -LiteralPath $script)) { Fail 'restore.ps1 tidak ditemukan di bundle.' }
        $file = [string](P 'file' '')
        if (-not $file) { Fail 'Parameter "file" (berkas .sql) wajib diisi.' }
        $psArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-BundleRoot', $Root, '-File', $file, '-Force')
        if ([bool](P 'stopServices' $false)) { $psArgs += '-StopServices' }
        $out = Invoke-Native 'powershell' $psArgs | Out-String
        $code = $LASTEXITCODE
        if ($code -eq 0) { Add-Step 'restore' 'ok' 'Restore selesai.' }
        else { Add-Step 'restore' 'warn' "Restore keluar dengan kode $code." }
        $script:ResultData = @{ output = ($out -split "`n" | Select-Object -Last 5) -join "`n" }
    }

    'apply-all' {
        Assert-Admin
        # Urutan instalasi lengkap; setiap langkah memanggil aksi terkait.
        $sequence = @('apply-config', 'install-vc', 'init-mysql', 'install-mysql-service', 'setup-database', 'install-apache-service', 'install-supervisor-service', 'setup-app', 'create-admin', 'register-com', 'configure-firewall', 'health')
        $total = $sequence.Count
        $idx = 0
        foreach ($a in $sequence) {
            $idx++
            [Console]::Error.WriteLine("##PROGRESS " + (@{ i = $idx; total = $total; action = $a; state = 'start' } | ConvertTo-Json -Compress))
            $scriptPath = $PSCommandPath
            $childArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $scriptPath, '-Action', $a, '-BundleRoot', $Root)
            if ($PayloadFile) { $childArgs += @('-PayloadFile', $PayloadFile) }
            elseif ($PayloadJson) { $childArgs += @('-PayloadJson', $PayloadJson) }
            $child = Invoke-Native 'powershell' $childArgs | Out-String
            $stepOk = $false
            try {
                $obj = $child | ConvertFrom-Json
                foreach ($s in $obj.steps) { $script:Steps.Add($s) }
                $stepOk = [bool]$obj.ok
                if (-not $obj.ok) { Add-Warning "Langkah '$a' gagal; instalasi dihentikan." }
            } catch {
                Add-Warning "Langkah '$a' gagal dijalankan."
            }
            [Console]::Error.WriteLine("##PROGRESS " + (@{ i = $idx; total = $total; action = $a; state = 'done'; ok = $stepOk } | ConvertTo-Json -Compress))
            if (-not $stepOk) { break }
        }
    }

    default { Fail "Aksi tidak dikenal: $Action" }
}

[Console]::Out.Write(([ordered]@{
            ok       = $true
            action   = $Action
            steps    = $script:Steps
            data     = $script:ResultData
            warnings = $script:Warnings
        } | ConvertTo-Json -Depth 8))
