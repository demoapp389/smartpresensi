# ---------------------------------------------------------------------------
# SmartPresensi - Preflight (deteksi prasyarat runtime)
#
# Modul ini hanya mendefinisikan fungsi. Bisa di-dot-source oleh bootstrap.ps1:
#     $PreflightLibraryOnly = $true
#     . (Join-Path $PSScriptRoot 'preflight.ps1')
#
# Atau dijalankan langsung untuk mencetak laporan:
#     powershell -ExecutionPolicy Bypass -File scripts\preflight.ps1
#     powershell -ExecutionPolicy Bypass -File scripts\preflight.ps1 -Json
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [switch]$Json
)

Set-StrictMode -Version Latest

function Get-SPProjectRoot {
    return (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
}

function Read-DotEnv {
    param([Parameter(Mandatory)][string]$Path)
    $map = @{}
    if (-not (Test-Path -LiteralPath $Path)) { return $map }
    foreach ($line in Get-Content -LiteralPath $Path) {
        $t = $line.Trim()
        if ($t -eq '' -or $t.StartsWith('#')) { continue }
        $i = $t.IndexOf('=')
        if ($i -le 0) { continue }
        $k = $t.Substring(0, $i).Trim()
        $v = $t.Substring($i + 1).Trim()
        if ($v.Length -ge 2 -and (($v[0] -eq '"' -and $v[-1] -eq '"') -or ($v[0] -eq "'" -and $v[-1] -eq "'"))) {
            $v = $v.Substring(1, $v.Length - 2)
        }
        $map[$k] = $v
    }
    return $map
}

function Get-PhpVersionAt {
    param([Parameter(Mandatory)][string]$Exe)
    try {
        $out = & $Exe -r 'echo PHP_VERSION;' 2>$null
        if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($out)) { return $null }
        return ([string]$out).Trim()
    } catch {
        return $null
    }
}

function Test-PhpVersionOk {
    param([string]$Version, [string]$Minimum = '8.3')
    if ([string]::IsNullOrWhiteSpace($Version)) { return $false }
    try {
        return ([version]($Version -replace '[^0-9.].*$', '')) -ge ([version]$Minimum)
    } catch {
        return $false
    }
}

function Get-OptionalPhpCandidates {
    $list = New-Object System.Collections.Generic.List[string]
    $list.Add((Join-Path $env:USERPROFILE '.config\herd\bin\php84\php.exe'))
    $list.Add((Join-Path $env:USERPROFILE '.config\herd\bin\php83\php.exe'))
    $list.Add((Join-Path $env:USERPROFILE '.config\herd\bin\php\php.exe'))
    foreach ($base in 'C:\laragon\bin\php', 'D:\laragon\bin\php') {
        if (Test-Path -LiteralPath $base) {
            Get-ChildItem -LiteralPath $base -Directory -ErrorAction SilentlyContinue |
                Sort-Object Name -Descending |
                ForEach-Object { $list.Add((Join-Path $_.FullName 'php.exe')) }
        }
    }
    $list.Add('C:\xampp\php\php.exe')
    $list.Add('C:\xampp74\php\php.exe')
    $list.Add('C:\php\php.exe')
    $list.Add('C:\tools\php\php.exe')
    $list.Add('C:\Program Files\PHP\php.exe')
    return $list
}

function Get-PhpComponent {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $required = @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'zip')

    $candidates = New-Object System.Collections.Generic.List[object]
    $candidates.Add([pscustomobject]@{ Path = (Join-Path $ProjectRoot 'runtime\php\php.exe'); Source = 'runtime' })

    $pathPhp = Get-Command php -ErrorAction SilentlyContinue
    if ($pathPhp) { $candidates.Add([pscustomobject]@{ Path = $pathPhp.Source; Source = 'system' }) }
    foreach ($c in (Get-OptionalPhpCandidates)) {
        if ($c) { $candidates.Add([pscustomobject]@{ Path = $c; Source = 'system' }) }
    }

    $found = $null
    $fallback = $null
    foreach ($c in $candidates) {
        if ([string]::IsNullOrWhiteSpace($c.Path) -or -not (Test-Path -LiteralPath $c.Path)) { continue }
        $ver = Get-PhpVersionAt -Exe $c.Path
        if (-not $ver) { continue }
        if (-not $fallback) { $fallback = [pscustomobject]@{ Path = $c.Path; Version = $ver; Source = $c.Source } }
        if (Test-PhpVersionOk -Version $ver) {
            $found = [pscustomobject]@{ Path = $c.Path; Version = $ver; Source = $c.Source }
            break
        }
    }

    if (-not $found) {
        return [pscustomobject]@{
            Found      = $false
            Path       = if ($fallback) { $fallback.Path } else { '' }
            Version    = if ($fallback) { $fallback.Version } else { '' }
            Source     = if ($fallback) { $fallback.Source } else { '' }
            Ok         = $false
            Missing    = @()
            Detail     = if ($fallback) { "PHP $($fallback.Version) ditemukan tetapi butuh >= 8.3" } else { 'PHP >= 8.3 tidak ditemukan' }
        }
    }

    $mods = @()
    try { $mods = @(& $found.Path -m 2>$null | ForEach-Object { $_.Trim().ToLowerInvariant() }) } catch { }
    $missing = @($required | Where-Object { $mods -notcontains $_ })

    return [pscustomobject]@{
        Found   = $true
        Path    = $found.Path
        Version = $found.Version
        Source  = $found.Source
        Ok      = ($missing.Count -eq 0)
        Missing = $missing
        Detail  = if ($missing.Count -eq 0) { "PHP $($found.Version) siap ($($found.Source))" } else { "Ekstensi kurang: $($missing -join ', ')" }
    }
}

function Test-TcpPort {
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

function Get-PortListenerProcessPath {
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

function Get-MysqlComponent {
    param(
        [Parameter(Mandatory)][string]$ProjectRoot,
        [Parameter(Mandatory)][hashtable]$Env,
        [object]$Php
    )
    $dbHost = if ($Env.ContainsKey('DB_HOST')) { $Env['DB_HOST'] } else { '127.0.0.1' }
    $dbPort = if ($Env.ContainsKey('DB_PORT')) { [int]$Env['DB_PORT'] } else { 3306 }
    $dbName = if ($Env.ContainsKey('DB_DATABASE')) { $Env['DB_DATABASE'] } else { '' }
    $dbUser = if ($Env.ContainsKey('DB_USERNAME')) { $Env['DB_USERNAME'] } else { 'root' }
    $dbPass = if ($Env.ContainsKey('DB_PASSWORD')) { $Env['DB_PASSWORD'] } else { '' }

    $runtimeClient = Join-Path $ProjectRoot 'runtime\mysql\bin\mysql.exe'
    $pathClient = Get-Command mysql -ErrorAction SilentlyContinue
    $client = if (Test-Path -LiteralPath $runtimeClient) { $runtimeClient } elseif ($pathClient) { $pathClient.Source } else { '' }

    $portOpen = Test-TcpPort -TargetHost $dbHost -Port $dbPort
    $connectOk = $false
    $databaseExists = $false
    $usersCount = $null
    $connectDetail = 'Tidak diuji'

    if ($portOpen -and $Php -and $Php.Found -and $dbName) {
        $probe = Join-Path $ProjectRoot ("sp-dbcheck-{0}.php" -f [guid]::NewGuid().ToString('N'))
        $phpCode = @"
<?php
`$host = getenv('SP_DB_HOST');
`$port = getenv('SP_DB_PORT');
`$name = getenv('SP_DB_NAME');
`$user = getenv('SP_DB_USER');
`$pass = getenv('SP_DB_PASS');
try {
    `$pdo = new PDO("mysql:host=`$host;port=`$port", `$user, `$pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    `$exists = `$pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . `$pdo->quote(`$name))->fetchColumn();
    `$users = null;
    if (`$exists) {
        try { `$users = (int)`$pdo->query("SELECT COUNT(*) FROM ``" . `$name . "``.``users``")->fetchColumn(); } catch (Throwable `$e2) { `$users = null; }
    }
    echo json_encode(['ok' => true, 'database_exists' => (bool)`$exists, 'users_count' => `$users]);
} catch (Throwable `$e) {
    echo json_encode(['ok' => false, 'error' => `$e->getMessage()]);
}
"@
        try {
            Set-Content -LiteralPath $probe -Value $phpCode -Encoding UTF8
            $env:SP_DB_HOST = $dbHost
            $env:SP_DB_PORT = [string]$dbPort
            $env:SP_DB_NAME = $dbName
            $env:SP_DB_USER = $dbUser
            $env:SP_DB_PASS = $dbPass
            $raw = & $Php.Path $probe 2>$null
            $obj = $null
            try { $obj = ($raw | Out-String).Trim() | ConvertFrom-Json } catch { $obj = $null }
            if ($obj -and $obj.ok) {
                $connectOk = $true
                $databaseExists = [bool]$obj.database_exists
                if ($databaseExists -and $null -ne $obj.users_count) { $usersCount = [int]$obj.users_count }
                if (-not $databaseExists) {
                    $connectDetail = "Terhubung; database '$dbName' belum ada"
                } elseif ($null -eq $usersCount) {
                    $connectDetail = "Terhubung; database '$dbName' ada (belum ada akun)"
                } elseif ($usersCount -eq 0) {
                    $connectDetail = "Terhubung; database '$dbName' ada, akun belum diisi"
                } else {
                    $connectDetail = "Terhubung; database '$dbName' ada, $usersCount akun"
                }
            } elseif ($obj -and $obj.error) {
                $owners = @(Get-PortListenerProcessPath -Port $dbPort)
                $connectDetail = if ($owners.Count -gt 0) {
                    "Gagal: $($obj.error) (port $dbPort dipakai: $($owners -join ', '))"
                } else {
                    "Gagal: $($obj.error)"
                }
            } else {
                $owners = @(Get-PortListenerProcessPath -Port $dbPort)
                $connectDetail = if ($owners.Count -gt 0) { "Gagal terhubung (port $dbPort dipakai: $($owners -join ', '))" } else { 'Gagal terhubung' }
            }
        } catch {
            $connectDetail = "Gagal uji koneksi: $($_.Exception.Message)"
        } finally {
            try {
                if ($probe -and (Test-Path -LiteralPath $probe)) { Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue }
            } catch { }
            foreach ($n in 'SP_DB_HOST', 'SP_DB_PORT', 'SP_DB_NAME', 'SP_DB_USER', 'SP_DB_PASS') {
                try {
                    if (Test-Path -LiteralPath ("Env:\{0}" -f $n)) { Remove-Item -LiteralPath ("Env:\{0}" -f $n) -ErrorAction SilentlyContinue }
                } catch { }
            }
        }
    } elseif (-not $portOpen) {
        $connectDetail = 'Port MySQL tidak terbuka'
    }

    return [pscustomobject]@{
        Host          = $dbHost
        Port          = $dbPort
        Database      = $dbName
        Client         = $client
        PortOpen       = $portOpen
        Connected      = $connectOk
        DatabaseExists = $databaseExists
        UsersCount     = $usersCount
        Ok             = $connectOk
        Detail         = $connectDetail
    }
}

function Get-PythonComponent {
    param([string]$ProjectRoot)
    $candidates = New-Object System.Collections.Generic.List[object]
    if ($ProjectRoot) {
        $runtimePy = Join-Path $ProjectRoot 'runtime\python\python.exe'
        if (Test-Path -LiteralPath $runtimePy) {
            $candidates.Add([pscustomobject]@{ Exe = $runtimePy; Args = @('--version'); Source = 'runtime' })
        }
    }
    foreach ($name in 'py', 'python', 'python3') {
        $cmd = Get-Command $name -ErrorAction SilentlyContinue
        if ($cmd) { $candidates.Add([pscustomobject]@{ Exe = $cmd.Source; Args = @('-3', '--version'); Source = 'system' }) }
    }
    $candidates.Add([pscustomobject]@{ Exe = 'py'; Args = @('-3', '--version'); Source = 'system' })
    $candidates.Add([pscustomobject]@{ Exe = 'python'; Args = @('--version'); Source = 'system' })

    foreach ($c in $candidates) {
        try {
            $out = (& $c.Exe @($c.Args) 2>&1 | Out-String).Trim()
            if ($out -match 'Python\s+(\d+)\.(\d+)') {
                $ver = "$($Matches[1]).$($Matches[2])"
                $ok = ([version]$ver) -ge ([version]'3.8')
                return [pscustomobject]@{ Found = $true; Path = $c.Exe; Version = $ver; Ok = $ok; Source = $c.Source; Detail = "Python $ver ($($c.Source))" }
            }
        } catch { }
    }
    return [pscustomobject]@{ Found = $false; Path = ''; Version = ''; Ok = $false; Source = ''; Detail = 'Python 3.8+ tidak ditemukan (dibutuhkan oleh connector mesin fingerprint)' }
}

function Get-DotNetComponent {
    $csc64 = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
    $csc32 = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe'
    $svc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\System.ServiceProcess.dll'
    $ok = ((Test-Path -LiteralPath $csc64) -or (Test-Path -LiteralPath $csc32)) -and (Test-Path -LiteralPath $svc)
    return [pscustomobject]@{ Ok = $ok; Detail = if ($ok) { '.NET Framework 4 tersedia' } else { '.NET Framework 4 tidak lengkap' } }
}

function Get-AppComponent {
    param([Parameter(Mandatory)][string]$ProjectRoot, [Parameter(Mandatory)][hashtable]$Env)
    $vendor = Join-Path $ProjectRoot 'vendor\autoload.php'
    $envFile = Join-Path $ProjectRoot '.env'
    $manifest = Join-Path $ProjectRoot 'public\build\manifest.json'
    $appKey = $Env.ContainsKey('APP_KEY') -and -not [string]::IsNullOrWhiteSpace($Env['APP_KEY'])

    $missing = New-Object System.Collections.Generic.List[string]
    if (-not (Test-Path -LiteralPath $vendor)) { $missing.Add('vendor\ (jalankan: composer install)') }
    if (-not (Test-Path -LiteralPath $envFile)) { $missing.Add('.env (salurkan dari .env.example)') }
    elseif (-not $appKey) { $missing.Add('APP_KEY (.env kosong)') }
    if (-not (Test-Path -LiteralPath $manifest)) { $missing.Add('public\build (jalankan: npm run build)') }

    return [pscustomobject]@{ Ok = ($missing.Count -eq 0); Missing = @($missing); Detail = if ($missing.Count -eq 0) { 'Aplikasi siap' } else { "Belum siap: $($missing -join '; ')" } }
}

function Test-SPAdmin {
    try {
        $id = [Security.Principal.WindowsIdentity]::GetCurrent()
        return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch {
        return $false
    }
}

function Invoke-Preflight {
    param([string]$ProjectRoot)
    if (-not $ProjectRoot) { $ProjectRoot = Get-SPProjectRoot }

    $envFile = Join-Path $ProjectRoot '.env'
    $envMap = Read-DotEnv -Path $envFile

    $php = Get-PhpComponent -ProjectRoot $ProjectRoot
    $python = Get-PythonComponent -ProjectRoot $ProjectRoot
    $dotnet = Get-DotNetComponent
    $app = Get-AppComponent -ProjectRoot $ProjectRoot -Env $envMap
    $mysql = Get-MysqlComponent -ProjectRoot $ProjectRoot -Env $envMap -Php $php

    $webPort = if ($envMap.ContainsKey('APP_URL') -and $envMap['APP_URL'] -match ':(\d+)') { [int]$Matches[1] } else { 8000 }
    $supervisorPort = if ($envMap.ContainsKey('SERVICE_SUPERVISOR_URL') -and $envMap['SERVICE_SUPERVISOR_URL'] -match ':(\d+)') { [int]$Matches[1] } else { 4372 }

    $ready = $php.Ok -and $mysql.Ok -and $python.Ok -and $dotnet.Ok -and $app.Ok

    return [pscustomobject]@{
        ProjectRoot    = $ProjectRoot
        Admin          = Test-SPAdmin
        Php            = $php
        Mysql          = $mysql
        Python         = $python
        DotNet         = $dotnet
        App            = $app
        WebPort        = $webPort
        SupervisorPort = $supervisorPort
        Ready          = $ready
    }
}

function Write-PreflightReport {
    param([Parameter(Mandatory)]$Report)
    function Mark([bool]$Ok) { if ($Ok) { 'OK' } else { 'X' } }

    Write-Host ''
    Write-Host '======================================================' -ForegroundColor Cyan
    Write-Host '  Pemeriksaan Prasyarat SmartPresensi' -ForegroundColor Cyan
    Write-Host '======================================================' -ForegroundColor Cyan
    Write-Host ("  Akar proyek : {0}" -f $Report.ProjectRoot)
    Write-Host ("  Administrator: {0}" -f (Mark $Report.Admin))
    Write-Host ''
    Write-Host ("  [{0}] PHP       : {1}" -f (Mark $Report.Php.Ok), $Report.Php.Detail)
    Write-Host ("  [{0}] MySQL     : {1}" -f (Mark $Report.Mysql.Ok), $Report.Mysql.Detail)
    Write-Host ("  [{0}] Python    : {1}" -f (Mark $Report.Python.Ok), $Report.Python.Detail)
    Write-Host ("  [{0}] .NET 4    : {1}" -f (Mark $Report.DotNet.Ok), $Report.DotNet.Detail)
    Write-Host ("  [{0}] Aplikasi  : {1}" -f (Mark $Report.App.Ok), $Report.App.Detail)
    Write-Host ''
    Write-Host ("  Status: {0}" -f $(if ($Report.Ready) { 'SIAP dijalankan' } else { 'BELUM siap - ada yang perlu disiapkan' })) -ForegroundColor $(if ($Report.Ready) { 'Green' } else { 'Yellow' })
    Write-Host ''
}

if (-not $env:PreflightLibraryOnly) {
    $report = Invoke-Preflight
    if ($Json) {
        $report | ConvertTo-Json -Depth 6
    } else {
        Write-PreflightReport -Report $report
    }
}
