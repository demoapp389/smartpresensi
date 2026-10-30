# ---------------------------------------------------------------------------
# SmartPresensi - Service CLI
#
# Menjalankan aksi kontrol service: status | start | stop | uninstall.
# Menangani elevasi (minta hak Administrator) seperti scripts\launch.ps1.
#
#   powershell -ExecutionPolicy Bypass -File scripts\service-cli.ps1 -Action status
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('status', 'network', 'start', 'stop', 'pause', 'enable', 'uninstall')][string]$Action,
    [switch]$Elevated
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Test-IsAdmin {
    try {
        $id = [Security.Principal.WindowsIdentity]::GetCurrent()
        return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch {
        return $false
    }
}

if (-not (Test-IsAdmin) -and -not $Elevated -and $Action -notin @('status', 'network')) {
    Write-Host 'SmartPresensi membutuhkan hak Administrator untuk aksi ini.' -ForegroundColor Yellow
    Write-Host 'Membuka jendela Administrator...'
    $argList = "-NoProfile -ExecutionPolicy Bypass -NoExit -File `"$PSCommandPath`" -Action $Action -Elevated"
    try {
        Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $argList | Out-Null
    } catch {
        Write-Host "Gagal meningkatkan hak akses: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host 'Klik kanan file .cmd lalu pilih "Run as administrator".'
        Read-Host 'Tekan Enter untuk menutup' | Out-Null
    }
    return
}

$env:PreflightLibraryOnly = $true
. (Join-Path $PSScriptRoot 'preflight.ps1')
. (Join-Path $PSScriptRoot 'runtime.ps1')
. (Join-Path $PSScriptRoot 'service.ps1')

$root = Get-SPProjectRoot
$envMap = Read-DotEnv -Path (Join-Path $root '.env')
$webPort = if ($envMap.ContainsKey('APP_URL') -and $envMap['APP_URL'] -match ':(\d+)') { [int]$Matches[1] } else { 8000 }
$supPort = if ($envMap.ContainsKey('SERVICE_SUPERVISOR_URL') -and $envMap['SERVICE_SUPERVISOR_URL'] -match ':(\d+)') { [int]$Matches[1] } else { 4372 }

function Show-SPStatus {
    Write-Host ''
    Write-Host '======================================================' -ForegroundColor Cyan
    Write-Host '  SmartPresensi - Status Service' -ForegroundColor Cyan
    Write-Host '======================================================' -ForegroundColor Cyan
    foreach ($state in (Get-SmartPresensiServiceState)) {
        $color = if ($state.Status -eq 'Running') { 'Green' } else { 'Yellow' }
        Write-Host ("  {0,-26} : {1} ({2})" -f $state.Name, $state.Status, $state.StartMode) -ForegroundColor $color
    }
    Write-Host ''
    $webOpen = Test-TcpPortOpen -Port $webPort
    $supOpen = Test-TcpPortOpen -Port $supPort
    $fw = Test-SPFirewallRule -Port $webPort
    $fwLabel = $(if ($fw) { 'firewall OK' } else { 'firewall belum dibuka' })
    Write-Host ("  Web (lokal) : http://localhost:{0}  [{1}]" -f $webPort, $(if ($webOpen) { 'aktif' } else { 'mati' })) -ForegroundColor $(if ($webOpen) { 'Green' } else { 'Yellow' })
    $lanIps = @(Get-SPLanIpList)
    if ($lanIps.Count -eq 0) {
        Write-Host '  Web (LAN)   : (tidak ada adaptor LAN aktif)' -ForegroundColor Yellow
    } else {
        for ($i = 0; $i -lt $lanIps.Count; $i++) {
            $label = if ($i -eq 0) { 'Web (LAN)' } else { '         ' }
            Write-Host ("  {0}   : http://{1}:{2}  [{3}]" -f $label, $lanIps[$i], $webPort, $fwLabel) -ForegroundColor $(if ($fw) { 'Green' } else { 'Yellow' })
        }
    }
    Write-Host ("  Supervisor  : http://127.0.0.1:{0}/api/status  [{1}]" -f $supPort, $(if ($supOpen) { 'aktif' } else { 'mati' })) -ForegroundColor $(if ($supOpen) { 'Green' } else { 'Yellow' })

    $owners = @(Get-SPPortOwner -Port $webPort)
    if ($owners.Count -gt 0) {
        $list = ($owners | ForEach-Object { "$($_.Name)#$($_.PID)" }) -join ', '
        Write-Host ("  Pemakai port {0}: {1}" -f $webPort, $list)
    }
    Write-Host ''
}

function Show-SPNetworkInfo {
    Write-Host ''
    Write-Host '======================================================' -ForegroundColor Cyan
    Write-Host '  SmartPresensi - Info Jaringan (akses LAN/Wi-Fi)' -ForegroundColor Cyan
    Write-Host '======================================================' -ForegroundColor Cyan
    Write-Host ("  Nama komputer : {0}" -f $env:COMPUTERNAME)
    $interfaces = @(Get-SPNetworkInterfaces)
    if ($interfaces.Count -eq 0) {
        Write-Host '  (Tidak ada adaptor jaringan IPv4.)' -ForegroundColor Yellow
    } else {
        foreach ($nic in $interfaces) {
            $state = if ($nic.Up) { 'aktif' } else { 'nonaktif' }
            Write-Host ''
            Write-Host ("  [{0}] {1}/{2}  ({3})" -f $nic.Alias, $nic.IP, $nic.Prefix, $state)
            Write-Host ("       Gateway : {0}" -f $(if ($nic.Gateway) { $nic.Gateway } else { '-' }))
            Write-Host ("       MAC     : {0}" -f $(if ($nic.Mac) { $nic.Mac } else { '-' }))
            if ($nic.Up) { Write-Host ("       URL     : http://{0}:{1}" -f $nic.IP, $webPort) -ForegroundColor Green }
        }
    }
    $fw = Test-SPFirewallRule -Port $webPort
    Write-Host ''
    Write-Host ("  Firewall port {0} : {1}" -f $webPort, $(if ($fw) { 'OK (inbound diizinkan)' } else { 'BELUM dibuka' })) -ForegroundColor $(if ($fw) { 'Green' } else { 'Yellow' })
    Write-Host ''
    Write-Host '  Agar alamat tidak berubah:' -ForegroundColor Cyan
    Write-Host '   - Isi DHCP Reservation di router untuk MAC di atas, atau'
    Write-Host '   - Set IP statis di Windows untuk adaptor LAN/Wi-Fi tersebut.'
    Write-Host '  Syarat akses dari perangkat lain (HP/laptop):'
    Write-Host '   - Berada di jaringan & subnet yang sama;'
    Write-Host '   - AP/Client Isolation pada router HARUS OFF;'
    Write-Host ("   - Buka lewat IP, contoh: http://{0}:{1}" -f (Get-SPLanIp), $webPort)
    Write-Host ''
}

switch ($Action) {
    'status' { Show-SPStatus }
    'network' { Show-SPNetworkInfo }
    'start' {
        Write-Host 'Menjalankan service SmartPresensi...' -ForegroundColor Cyan
        Add-SPFirewallRule -Port $webPort | Out-Null
        Start-SmartPresensiServices -ProjectRoot $root | Out-Null
        Start-Sleep -Seconds 2
        Show-SPStatus
    }
    'stop' {
        Write-Host 'Menghentikan service SmartPresensi...' -ForegroundColor Cyan
        Stop-SmartPresensiServices -ProjectRoot $root
        Show-SPStatus
    }
    'pause' {
        Write-Host 'Mempause autostart (service -> Manual, dihentikan)...' -ForegroundColor Cyan
        Pause-SmartPresensiAutostart -ProjectRoot $root
        Write-Host '  [OK] Service tidak lagi jalan otomatis saat boot. Data tetap aman.' -ForegroundColor Green
        Show-SPStatus
    }
    'enable' {
        Write-Host 'Mengaktifkan kembali autostart (service -> Auto)...' -ForegroundColor Cyan
        Add-SPFirewallRule -Port $webPort | Out-Null
        Enable-SmartPresensiAutostart
        Start-SmartPresensiServices -ProjectRoot $root | Out-Null
        Start-Sleep -Seconds 2
        Show-SPStatus
    }
    'uninstall' {
        Write-Host 'Menghapus service autostart SmartPresensi...' -ForegroundColor Cyan
        Uninstall-SmartPresensiAutostart -ProjectRoot $root
        Write-Host '  [OK] Service dihapus. Data tetap aman (tidak menyentuh database).' -ForegroundColor Green
        Show-SPStatus
    }
}
