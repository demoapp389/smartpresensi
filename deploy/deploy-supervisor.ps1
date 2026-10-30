param(
    [string]$ServiceName = 'SmartPresensiSupervisor',
    [string]$ServiceDir = (Join-Path $PSScriptRoot '..\service'),
    [string]$SourceExe = 'SmartPresensiSupervisor_new.exe',
    [string]$TargetExe = 'SmartPresensiSupervisor.exe',
    [int]$StartupTimeoutSeconds = 90
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ServiceDir = (Resolve-Path -LiteralPath $ServiceDir).Path
$sourcePath = Join-Path $ServiceDir $SourceExe
$targetPath = Join-Path $ServiceDir $TargetExe
$configPath = Join-Path $ServiceDir 'supervisor_config.ini'
$logDir = Join-Path (Split-Path $ServiceDir -Parent) 'storage\logs'
$backupDir = Join-Path (Split-Path $ServiceDir -Parent) 'storage\app\backups\supervisor'
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$logPath = Join-Path $logDir "supervisor_deploy_$stamp.log"

function Write-Log([string]$Message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Host $line
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
}

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Skrip ini membutuhkan PowerShell dengan hak Administrator (UAC).'
    }
}

function Wait-ServiceState([string]$Name, [string]$State, [int]$TimeoutSeconds) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $service = Get-Service -Name $Name -ErrorAction Stop
        if ($service.Status.ToString() -eq $State) { return $true }
        Start-Sleep -Seconds 1
    }

    return $false
}

function Get-SupervisorStatus([string]$Token) {
    return Invoke-RestMethod -Uri 'http://127.0.0.1:4372/api/status' `
        -Headers @{ 'X-Supervisor-Token' = $Token } -TimeoutSec 5
}

function Wait-SchedulerHealthy([string]$Token, [int]$TimeoutSeconds) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        try {
            $status = Get-SupervisorStatus $Token
            if ($status.scheduler -and $status.scheduler.status -eq 'running') {
                return $status
            }
        } catch {
        }
        Start-Sleep -Seconds 2
    }

    return $null
}

foreach ($dir in @($logDir, $backupDir)) {
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
}

Assert-Administrator

if (-not (Test-Path -LiteralPath $sourcePath)) {
    throw "Executable sumber tidak ditemukan: $sourcePath"
}

if (-not (Test-Path -LiteralPath $configPath)) {
    throw "Konfigurasi supervisor tidak ditemukan: $configPath"
}

$tokenMatch = Select-String -LiteralPath $configPath -Pattern '^auth_token=(.+)$' | Select-Object -First 1
if (-not $tokenMatch) {
    throw 'auth_token tidak ditemukan di supervisor_config.ini.'
}
$supervisorToken = $tokenMatch.Matches[0].Groups[1].Value.Trim()

$backupPath = Join-Path $backupDir "$TargetExe.$stamp.bak"
$serviceExisted = $null -ne (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue)
$rolledBack = $false

Write-Log "Memulai deploy supervisor. Log: $logPath"
Write-Log "Service: $ServiceName; target: $targetPath"

if (Test-Path -LiteralPath $targetPath) {
    Copy-Item -LiteralPath $targetPath -Destination $backupPath -Force
    Write-Log "Backup executable lama: $backupPath"
}

try {
    if ($serviceExisted) {
        Write-Log 'Menghentikan service supervisor...'
        Stop-Service -Name $ServiceName -Force
        if (-not (Wait-ServiceState $ServiceName 'Stopped' 30)) {
            throw 'Service supervisor tidak berhenti dalam 30 detik.'
        }
        Write-Log 'Service berhenti.'
    }

    Write-Log 'Mengganti executable supervisor...'
    Copy-Item -LiteralPath $sourcePath -Destination $targetPath -Force

    Write-Log 'Menjalankan service supervisor...'
    if ($serviceExisted) {
        Start-Service -Name $ServiceName
    } else {
        throw 'Service belum terdaftar; deploy ini hanya mengganti executable service yang sudah ada.'
    }

    Write-Log 'Menunggu supervisor API dan scheduler...'
    $status = Wait-SchedulerHealthy $supervisorToken $StartupTimeoutSeconds
    if (-not $status) {
        throw "Scheduler belum berjalan dalam $StartupTimeoutSeconds detik."
    }

    Write-Log "Scheduler=$($status.scheduler.status); connector=$($status.connector.status); worker=$($status.worker.status)"
    Write-Log 'Deploy supervisor berhasil.'
} catch {
    Write-Log "Deploy gagal: $($_.Exception.Message)"
    if (Test-Path -LiteralPath $backupPath) {
        Write-Log 'Mengembalikan executable lama...'
        if ($serviceExisted) {
            Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
            Wait-ServiceState $ServiceName 'Stopped' 30 | Out-Null
        }
        Copy-Item -LiteralPath $backupPath -Destination $targetPath -Force
        if ($serviceExisted) {
            Start-Service -Name $ServiceName
            Write-Log 'Service dijalankan kembali dengan executable lama.'
        }
        $rolledBack = $true
    }
    throw
} finally {
    if ($rolledBack) {
        Write-Log 'Rollback selesai. Periksa log supervisor dan konfigurasi.'
    }
}
