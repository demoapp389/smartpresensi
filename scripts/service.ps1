# ---------------------------------------------------------------------------
# SmartPresensi - Windows Service (autostart saat boot)
#
# Modul fungsi, di-dot-source oleh bootstrap.ps1 dan service-cli.ps1:
#     . (Join-Path $PSScriptRoot 'service.ps1')
#
# Mendaftarkan dua service Windows agar sistem berjalan otomatis tanpa jendela:
#   * SmartPresensiMySQL      - MySQL bawaan runtime\mysql (port 3307), hanya
#                               bila MySQL yang dipakai memang runtime\mysql.
#   * SmartPresensiSupervisor - connector + queue worker + web server Laravel.
#
# Seluruh fungsi bersifat idempoten: aman dipanggil berulang kali.
# ---------------------------------------------------------------------------
Set-StrictMode -Version Latest

function Test-SPServiceExists {
    param([Parameter(Mandatory)][string]$Name)
    return $null -ne (Get-Service -Name $Name -ErrorAction SilentlyContinue)
}

function Get-SPServiceBinaryPath {
    param([Parameter(Mandatory)][string]$Name)
    try {
        $svc = Get-CimInstance -ClassName Win32_Service -Filter ("Name='{0}'" -f $Name) -ErrorAction Stop
        if ($svc) { return [string]$svc.PathName }
    } catch { }
    return ''
}

function Get-SPStaleServices {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $stale = New-Object System.Collections.Generic.List[string]

    $mysqlName = Get-SPMysqlServiceName
    if (Test-SPServiceExists -Name $mysqlName) {
        $bin = Get-SPServiceBinaryPath -Name $mysqlName
        $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
        if ($bin -and ($bin -notlike ('*' + $p.Mysqld + '*'))) { $stale.Add($mysqlName) }
    }

    $supName = Get-SPSupervisorServiceName
    $supExe = Join-Path $ProjectRoot 'service\SmartPresensiSupervisor.exe'
    if (Test-SPServiceExists -Name $supName) {
        $bin = Get-SPServiceBinaryPath -Name $supName
        if ($bin -and ($bin -notlike ('*' + $supExe + '*'))) { $stale.Add($supName) }
    }

    return @($stale)
}

function Repair-SmartPresensiServices {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $repaired = New-Object System.Collections.Generic.List[string]

    # Service MySQL: bila menunjuk runtime lain (folder dipindah/dikopi), lepaskan
    # agar port tidak direbut instance lama. Service dipasang ulang di tahap akhir.
    $mysqlName = Get-SPMysqlServiceName
    $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
    if ((Test-SPServiceExists -Name $mysqlName) -and $p.Present -and $p.Initialized) {
        $bin = Get-SPServiceBinaryPath -Name $mysqlName
        if ($bin -and ($bin -notlike ('*' + $p.Mysqld + '*'))) {
            & sc.exe stop $mysqlName | Out-Null
            Start-Sleep -Seconds 2
            & sc.exe delete $mysqlName | Out-Null
            Start-Sleep -Seconds 3
            $repaired.Add($mysqlName)
        }
    }

    $supName = Get-SPSupervisorServiceName
    $supExe = Join-Path $ProjectRoot 'service\SmartPresensiSupervisor.exe'
    if ((Test-SPServiceExists -Name $supName) -and (Test-Path -LiteralPath $supExe)) {
        $bin = Get-SPServiceBinaryPath -Name $supName
        if ($bin -and ($bin -notlike ('*' + $supExe + '*'))) {
            & sc.exe stop $supName | Out-Null
            Start-Sleep -Seconds 2
            & sc.exe delete $supName | Out-Null
            Start-Sleep -Seconds 3
            $repaired.Add($supName)
        }
    }

    return @($repaired)
}

function Set-SPServiceRecovery {
    param([Parameter(Mandatory)][string]$Name)
    # Restart otomatis bila service berhenti tak terduga (crash).
    & sc.exe failure $Name 'reset=' 86400 'actions=' 'restart/5000/restart/10000/restart/30000' | Out-Null
}

function Test-SPUsesBundledMysql {
    param([Parameter(Mandatory)][string]$ProjectRoot, $Report)
    $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
    if (-not ($p.Present -and $p.Initialized)) { return $false }
    if ($null -eq $Report -or $null -eq $Report.Mysql) { return $false }
    return ([int]$Report.Mysql.Port -eq [int]$p.Port)
}

function Stop-BundledMysql {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
    if (-not $p.Present) { return }
    if (-not (Test-TcpPortOpen -Port $p.Port)) { return }

    $admin = Join-Path (Split-Path -Parent $p.Mysqld) 'mysqladmin.exe'
    if (Test-Path -LiteralPath $admin) {
        Write-Host '  Menghentikan MySQL bawaan (persiapan pemasangan service)...'
        try { & $admin '-h' '127.0.0.1' '-P' "$($p.Port)" '-u' 'root' 'shutdown' 2>$null | Out-Null } catch { }
        Start-Sleep -Seconds 2
    }
    if (Test-TcpPortOpen -Port $p.Port) {
        Get-Process -Name mysqld -ErrorAction SilentlyContinue |
            Where-Object { $_.Path -and ($_.Path -ieq $p.Mysqld) } |
            Stop-Process -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }
}

function Install-SPWindowsService {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$DisplayName,
        [Parameter(Mandatory)][string]$BinaryPathName
    )
    if (Test-SPServiceExists -Name $Name) {
        & sc.exe stop $Name | Out-Null
        Start-Sleep -Seconds 2
        & sc.exe delete $Name | Out-Null
        Start-Sleep -Seconds 3
    }
    New-Service -Name $Name -BinaryPathName $BinaryPathName -DisplayName $DisplayName -StartupType Automatic | Out-Null
    Set-SPServiceRecovery -Name $Name
}

function Install-SPMysqlService {
    param([Parameter(Mandatory)][string]$ProjectRoot, [switch]$Force)
    $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
    if (-not ($p.Present -and $p.Initialized)) { return $false }

    $name = Get-SPMysqlServiceName
    if (Test-SPServiceExists -Name $name) {
        $currentBin = Get-SPServiceBinaryPath -Name $name
        if ((-not $Force) -and $currentBin -and ($currentBin -like ('*' + $p.Mysqld + '*'))) {
            # Sudah terpasang menunjuk ke runtime yang sama: cukup pastikan auto-start.
            & sc.exe config $name 'start=' auto | Out-Null
            Set-SPServiceRecovery -Name $name
            return $true
        }
        # Path berbeda (folder dipindah) atau dipaksa: pasang ulang.
        & sc.exe stop $name | Out-Null
        Start-Sleep -Seconds 2
        & sc.exe delete $name | Out-Null
        Start-Sleep -Seconds 3
    }

    Stop-BundledMysql -ProjectRoot $ProjectRoot
    & $p.Mysqld --install $name "--defaults-file=$($p.Ini)" | Out-Null
    Start-Sleep -Seconds 1
    & sc.exe config $name 'start=' auto | Out-Null
    Set-SPServiceRecovery -Name $name
    return $true
}

function Install-SmartPresensiAutostart {
    param([Parameter(Mandatory)][string]$ProjectRoot, $Report, [switch]$ForceMysql)
    $exe = Join-Path $ProjectRoot 'service\SmartPresensiSupervisor.exe'
    if (-not (Test-Path -LiteralPath $exe)) {
        throw 'SmartPresensiSupervisor.exe tidak ditemukan di folder service\.'
    }

    Install-SPWindowsService -Name (Get-SPSupervisorServiceName) -DisplayName 'SmartPresensi Supervisor' -BinaryPathName ('"{0}"' -f $exe)

    # Bersihkan proses web/connector sisa agar port web bebas untuk service baru.
    Stop-SPStrayProcesses -ProjectRoot $ProjectRoot

    $mysql = $false
    if ((Test-SPUsesBundledMysql -ProjectRoot $ProjectRoot -Report $Report) -or (Test-SPServiceExists -Name (Get-SPMysqlServiceName))) {
        $mysql = Install-SPMysqlService -ProjectRoot $ProjectRoot -Force:$ForceMysql
    }

    return [pscustomobject]@{ Supervisor = $true; Mysql = [bool]$mysql }
}

function Start-SmartPresensiServices {
    param([Parameter(Mandatory)][string]$ProjectRoot, [int]$WaitMysqlSec = 90)
    $mysqlName = Get-SPMysqlServiceName
    if (Test-SPServiceExists -Name $mysqlName) {
        if ((Get-Service -Name $mysqlName).Status -ne 'Running') {
            try { Start-Service -Name $mysqlName -ErrorAction Stop } catch { }
        }
        $p = Get-BundledMysqlPaths -ProjectRoot $ProjectRoot
        $deadline = (Get-Date).AddSeconds($WaitMysqlSec)
        while ((Get-Date) -lt $deadline -and -not (Test-TcpPortOpen -Port $p.Port)) { Start-Sleep -Milliseconds 750 }
    }

    $supName = Get-SPSupervisorServiceName
    if (Test-SPServiceExists -Name $supName) {
        if ((Get-Service -Name $supName).Status -ne 'Running') {
            try { Start-Service -Name $supName -ErrorAction Stop } catch { }
        }
    }
}

function Stop-SmartPresensiServices {
    param([string]$ProjectRoot)
    foreach ($name in @((Get-SPSupervisorServiceName), (Get-SPMysqlServiceName))) {
        if (Test-SPServiceExists -Name $name) {
            if ((Get-Service -Name $name).Status -eq 'Running') {
                try { Stop-Service -Name $name -Force -ErrorAction Stop } catch { }
                Start-Sleep -Seconds 2
            }
        }
    }
    if ($ProjectRoot) { Stop-SPStrayProcesses -ProjectRoot $ProjectRoot }
}

function Uninstall-SmartPresensiAutostart {
    param([string]$ProjectRoot)
    foreach ($name in @((Get-SPSupervisorServiceName), (Get-SPMysqlServiceName))) {
        if (Test-SPServiceExists -Name $name) {
            & sc.exe stop $name | Out-Null
            Start-Sleep -Seconds 2
            & sc.exe delete $name | Out-Null
            Start-Sleep -Seconds 2
        }
    }
    if ($ProjectRoot) { Stop-SPStrayProcesses -ProjectRoot $ProjectRoot }
}

function Get-SmartPresensiServiceState {
    $result = @()
    $startMode = @{}
    try {
        foreach ($svc in (Get-CimInstance -ClassName Win32_Service -Filter "Name='SmartPresensiMySQL' OR Name='SmartPresensiSupervisor'" -ErrorAction SilentlyContinue)) {
            $startMode[[string]$svc.Name] = [string]$svc.StartMode
        }
    } catch { }

    foreach ($name in @((Get-SPMysqlServiceName), (Get-SPSupervisorServiceName))) {
        $svc = Get-Service -Name $name -ErrorAction SilentlyContinue
        $result += [pscustomobject]@{
            Name      = $name
            Exists    = ($null -ne $svc)
            Status    = if ($svc) { [string]$svc.Status } else { 'Tidak terpasang' }
            StartMode = if ($startMode.ContainsKey($name)) { $startMode[$name] } else { '-' }
        }
    }
    return $result
}

function Set-SPServiceStartMode {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][ValidateSet('auto', 'demand', 'disabled')][string]$Mode
    )
    if (-not (Test-SPServiceExists -Name $Name)) { return $false }
    & sc.exe config $Name 'start=' $Mode | Out-Null
    return $true
}

function Pause-SmartPresensiAutostart {
    # Matikan sementara tanpa uninstall: service tidak lagi jalan saat boot,
    # tetapi masih bisa dijalankan manual (start) kapan saja.
    param([string]$ProjectRoot)
    foreach ($name in @((Get-SPSupervisorServiceName), (Get-SPMysqlServiceName))) {
        if (Test-SPServiceExists -Name $name) {
            Set-SPServiceStartMode -Name $name -Mode 'demand' | Out-Null
            if ((Get-Service -Name $name).Status -eq 'Running') {
                try { Stop-Service -Name $name -Force -ErrorAction Stop } catch { }
                Start-Sleep -Seconds 2
            }
        }
    }
    if ($ProjectRoot) { Stop-SPStrayProcesses -ProjectRoot $ProjectRoot }
}

function Enable-SmartPresensiAutostart {
    foreach ($name in @((Get-SPSupervisorServiceName), (Get-SPMysqlServiceName))) {
        if (Test-SPServiceExists -Name $name) {
            Set-SPServiceStartMode -Name $name -Mode 'auto' | Out-Null
            Set-SPServiceRecovery -Name $Name
        }
    }
}

function Get-SPFirewallRuleName {
    param([Parameter(Mandatory)][int]$Port)
    return ("SmartPresensi Web (TCP {0})" -f $Port)
}

function Test-SPFirewallRule {
    param([Parameter(Mandatory)][int]$Port)
    $name = Get-SPFirewallRuleName -Port $Port
    try {
        if (Get-NetFirewallRule -DisplayName $name -ErrorAction Stop) { return $true }
    } catch { }
    # Non-admin dapat ditolak Get-NetFirewallRule ("Access is denied"); netsh tetap bisa membaca.
    try {
        $out = & netsh advfirewall firewall show rule name="$name" 2>$null | Out-String
        return ($out -match 'Rule Name:' -and $out -notmatch 'No rules match')
    } catch { }
    return $false
}

function Add-SPFirewallRule {
    param([Parameter(Mandatory)][int]$Port)
    $name = Get-SPFirewallRuleName -Port $Port
    if (Test-SPFirewallRule -Port $Port) { return $true }
    try {
        New-NetFirewallRule -DisplayName $name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -Profile Any -ErrorAction Stop | Out-Null
        return $true
    } catch {
        & netsh advfirewall firewall add rule name="$name" dir=in action=allow protocol=TCP localport=$Port | Out-Null
        return (Test-SPFirewallRule -Port $Port)
    }
}

function Get-SPPortOwner {
    param([Parameter(Mandatory)][int]$Port)
    $result = @()
    $conns = @()
    try { $conns = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue } catch { }
    foreach ($c in $conns) {
        $proc = Get-Process -Id $c.OwningProcess -ErrorAction SilentlyContinue
        $result += [pscustomobject]@{
            Port = $c.LocalPort
            PID  = $c.OwningProcess
            Name = if ($proc) { $proc.ProcessName } else { 'unknown' }
        }
    }
    return $result
}

function Test-SPVirtualNetworkAdapter {
    param($Adapter)
    if (-not $Adapter) { return $false }
    $text = ('{0} {1} {2}' -f $Adapter.Name, $Adapter.InterfaceDescription, $Adapter.InterfaceAlias)
    return ($text -match '(?i)(virtualbox|vmware|vmnet|hyper-?v|vethernet|loopback|host-only|tap[- ]|tun|ppp|tailscale|zerotier|hamachi|openvpn|wireguard|nordvpn|expressvpn|docker|wsl|bluetooth|wi-fi direct|npcap|pseudo|isatap|teredo|virtual adapter|virtual ethernet)')
}

function Get-SPNetworkInterfaces {
    param([switch]$UpOnly, [switch]$IncludeVirtual)
    $result = @()
    try {
        foreach ($ip in (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' })) {
            $adapter = Get-NetAdapter -InterfaceIndex $ip.InterfaceIndex -ErrorAction SilentlyContinue
            if (-not $IncludeVirtual -and (Test-SPVirtualNetworkAdapter -Adapter $adapter)) { continue }
            $isUp = ($adapter -and $adapter.Status -eq 'Up')
            if ($UpOnly -and -not $isUp) { continue }
            $gw = ''
            try {
                $route = Get-NetRoute -InterfaceIndex $ip.InterfaceIndex -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue | Select-Object -First 1
                if ($route) { $gw = [string]$route.NextHop }
            } catch { }
            $result += [pscustomobject]@{
                Alias   = [string]$ip.InterfaceAlias
                IP      = [string]$ip.IPAddress
                Prefix  = [int]$ip.PrefixLength
                Gateway = $gw
                Mac     = if ($adapter) { [string]$adapter.MacAddress } else { '' }
                Up      = [bool]$isUp
            }
        }
    } catch { }
    return $result
}

function Get-SPLanIpList {
    $list = @(Get-SPNetworkInterfaces -UpOnly | ForEach-Object { $_.IP })
    return $list
}

function Get-SPLanIp {
    $interfaces = @(Get-SPNetworkInterfaces -UpOnly)
    $private = $interfaces | Where-Object { $_.IP -match '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' } | Select-Object -First 1
    if ($private) { return $private.IP }
    $first = $interfaces | Select-Object -First 1
    if ($first) { return $first.IP }
    return '127.0.0.1'
}

function Stop-SPStrayProcesses {
    param([Parameter(Mandatory)][string]$ProjectRoot)
    # "artisan serve" meluncurkan "php -S" sebagai anak; saat induk dibunuh,
    # anak bisa tertinggal dan menahan port web. Bersihkan proses milik proyek ini.
    Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='python.exe' OR Name='SolutionX601Connector.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            ($_.ExecutablePath -and $_.ExecutablePath.StartsWith($ProjectRoot, [System.StringComparison]::OrdinalIgnoreCase)) -or
            ($_.CommandLine -and $_.CommandLine -like ('*' + $ProjectRoot + '*'))
        } |
        ForEach-Object {
            try { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue } catch { }
        }
}
