# ---------------------------------------------------------------------------
# SmartPresensi - Setup Server (wizard prasyarat berbasis browser)
#
# Menyajikan halaman scripts\setup-ui.html dan API kecil untuk mengunduh /
# mengimpor komponen yang kurang ke folder runtime\. Dipanggil oleh
# scripts\bootstrap.ps1 saat sistem belum siap.
#
#   powershell -ExecutionPolicy Bypass -File scripts\setup-server.ps1
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [int]$Port = 4380,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$env:PreflightLibraryOnly = $true
. (Join-Path $PSScriptRoot 'preflight.ps1')
. (Join-Path $PSScriptRoot 'runtime.ps1')

$script:Root = Get-SPProjectRoot
$script:RuntimeDir = Join-Path $script:Root 'runtime'
$script:CacheDir = Join-Path $script:RuntimeDir '_cache'
$script:StateDir = Join-Path $PSScriptRoot 'state'
$script:Fetcher = Join-Path $PSScriptRoot 'fetch.ps1'
$script:ManifestPath = Join-Path $PSScriptRoot 'components.json'
$script:Running = $true

New-Item -ItemType Directory -Force -Path $script:CacheDir, $script:StateDir | Out-Null

function Get-Prop {
    param($Object, [string]$Name, $Default = $null)
    if ($null -eq $Object) { return $Default }
    $p = $Object.PSObject.Properties[$Name]
    if ($p -and $null -ne $p.Value) { return $p.Value }
    return $Default
}

$script:Manifest = Get-Content -Raw -LiteralPath $script:ManifestPath | ConvertFrom-Json
$script:ReportCache = $null
$script:ReportTime = [DateTime]::MinValue

function Get-ReportCached {
    $now = Get-Date
    if ($script:ReportCache -and (($now - $script:ReportTime).TotalSeconds -lt 2)) { return $script:ReportCache }
    Use-BundledRuntime -ProjectRoot $script:Root | Out-Null
    $script:ReportCache = Invoke-Preflight -ProjectRoot $script:Root
    $script:ReportTime = $now
    return $script:ReportCache
}

function Get-ComponentsView {
    $report = Get-ReportCached
    $list = New-Object System.Collections.Generic.List[object]
    foreach ($c in $script:Manifest.components) {
        $mapsTo = Get-Prop $c 'mapsTo' $c.id
        $ok = $false
        $detail = ''
        $source = ''
        switch ($mapsTo) {
            'php' { $ok = $report.Php.Ok; $detail = $report.Php.Detail; $source = $report.Php.Source }
            'mysql' { $ok = $report.Mysql.Ok; $detail = $report.Mysql.Detail }
            'python' { $ok = $report.Python.Ok; $detail = $report.Python.Detail; $source = $report.Python.Source }
            'dotnet' { $ok = $report.DotNet.Ok; $detail = $report.DotNet.Detail }
            'vcredist' {
                $ok = Test-Path -LiteralPath (Join-Path $env:WINDIR 'System32\vcruntime140.dll')
                $detail = if ($ok) { 'Sudah terpasang' } else { 'Belum terpasang' }
            }
            default { $detail = 'Tidak diketahui' }
        }
        $list.Add([pscustomobject]@{
                id          = $c.id
                name        = (Get-Prop $c 'name' $c.id)
                mapsTo      = $mapsTo
                kind        = (Get-Prop $c 'kind' 'zip')
                optional    = [bool](Get-Prop $c 'optional' $false)
                sizeMb      = (Get-Prop $c 'sizeMb' 0)
                url         = (Get-Prop $c 'url' '')
                description = (Get-Prop $c 'description' '')
                ok          = $ok
                detail      = $detail
                source      = $source
            })
    }
    return $list
}

function Get-ProgressFor {
    param([Parameter(Mandatory)][string]$Id)
    $file = Join-Path $script:StateDir ("progress-{0}.json" -f $Id)
    if (-not (Test-Path -LiteralPath $file)) { return [pscustomobject]@{ status = 'idle'; percent = 0; message = '' } }
    try {
        return (Get-Content -Raw -LiteralPath $file | ConvertFrom-Json)
    } catch {
        return [pscustomobject]@{ status = 'idle'; percent = 0; message = 'Membaca progres...' }
    }
}

function Send-Bytes {
    param([Parameter(Mandatory)]$Context, [byte[]]$Bytes, [string]$ContentType, [int]$StatusCode = 200)
    $Context.Response.StatusCode = $StatusCode
    $Context.Response.ContentType = $ContentType
    $Context.Response.ContentLength64 = $Bytes.Length
    $Context.Response.OutputStream.Write($Bytes, 0, $Bytes.Length)
    $Context.Response.OutputStream.Close()
}

function Send-Text {
    param([Parameter(Mandatory)]$Context, [string]$Text, [string]$ContentType = 'text/plain; charset=utf-8', [int]$StatusCode = 200)
    Send-Bytes -Context $Context -Bytes ([System.Text.Encoding]::UTF8.GetBytes($Text)) -ContentType $ContentType -StatusCode $StatusCode
}

function Send-Json {
    param([Parameter(Mandatory)]$Context, $Data)
    Send-Text -Context $Context -Text ($Data | ConvertTo-Json -Depth 8 -Compress) -ContentType 'application/json; charset=utf-8'
}

function Get-ComponentById {
    param([Parameter(Mandatory)][string]$Id)
    return $script:Manifest.components | Where-Object { $_.id -eq $Id } | Select-Object -First 1
}

function Start-ComponentFetch {
    param([Parameter(Mandatory)][string]$Id)
    $comp = Get-ComponentById -Id $Id
    if (-not $comp) { return $false }
    if ((Get-Prop $comp 'kind' 'zip') -eq 'external') { return $false }
    $progressFile = Join-Path $script:StateDir ("progress-{0}.json" -f $Id)
    if (Test-Path -LiteralPath $progressFile) { Remove-Item -LiteralPath $progressFile -Force -ErrorAction SilentlyContinue }
    Start-Process -FilePath 'powershell' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script:Fetcher, '-Component', $Id) -WindowStyle Hidden | Out-Null
    return $true
}

function Save-UploadedFileStream {
    param([Parameter(Mandatory)]$Request, [Parameter(Mandatory)][string]$Id)
    $comp = Get-ComponentById -Id $Id
    if (-not $comp) { return $false }
    $fileName = Get-Prop $comp 'file' ''
    if (-not $fileName) { return $false }
    $dest = Join-Path $script:CacheDir $fileName
    $fs = [System.IO.File]::Create($dest)
    try {
        $buffer = New-Object byte[] 1048576
        $remaining = [int64]$Request.ContentLength64
        while ($remaining -gt 0) {
            $toRead = [int][Math]::Min([int64]$buffer.Length, $remaining)
            $read = $Request.InputStream.Read($buffer, 0, $toRead)
            if ($read -le 0) { break }
            $fs.Write($buffer, 0, $read)
            $remaining -= $read
        }
    } finally {
        $fs.Dispose()
    }
    return $true
}

function Invoke-Request {
    param([Parameter(Mandatory)]$Context)
    $req = $Context.Request
    $path = $req.Url.AbsolutePath
    $query = [System.Web.HttpUtility]::ParseQueryString($req.Url.Query)
    $id = [string]$query['id']

    switch -Regex ($path) {
        '^/$' {
            $html = Join-Path $PSScriptRoot 'setup-ui.html'
            if (Test-Path -LiteralPath $html) {
                Send-Bytes -Context $Context -Bytes ([System.IO.File]::ReadAllBytes($html)) -ContentType 'text/html; charset=utf-8'
            } else {
                Send-Text -Context $Context -Text 'setup-ui.html tidak ditemukan.' -StatusCode 500
            }
            return
        }
        '^/favicon\.ico$' { Send-Bytes -Context $Context -Bytes @() -ContentType 'image/x-icon' -StatusCode 204; return }
        '^/api/status$' {
            $report = Get-ReportCached
            Send-Json -Context $Context -Data ([pscustomobject]@{
                    root       = $script:Root
                    ready      = $report.Ready
                    webPort    = $report.WebPort
                    components = @(Get-ComponentsView)
                })
            return
        }
        '^/api/progress$' {
            Send-Json -Context $Context -Data (Get-ProgressFor -Id $id)
            return
        }
        '^/api/fetch$' {
            $started = $false
            if ($req.HttpMethod -eq 'POST' -and $id) { $started = Start-ComponentFetch -Id $id }
            Send-Json -Context $Context -Data ([pscustomobject]@{ started = $started })
            return
        }
        '^/api/upload$' {
            $ok = $false
            if ($req.HttpMethod -eq 'POST' -and $id) {
                $ok = Save-UploadedFileStream -Request $req -Id $id
            }
            Send-Json -Context $Context -Data ([pscustomobject]@{ ok = $ok })
            return
        }
        '^/api/finish$' {
            Send-Json -Context $Context -Data ([pscustomobject]@{ ok = $true })
            $script:Running = $false
            return
        }
        default {
            Send-Text -Context $Context -Text 'Not found' -StatusCode 404
            return
        }
    }
}

# ---------------------------------------------------------------------------
Add-Type -AssemblyName System.Web | Out-Null

while (Test-TcpPortOpen -Port $Port) { $Port++ }
$prefix = "http://127.0.0.1:$Port/"
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add($prefix)
try {
    $listener.Start()
} catch {
    Write-Error ("Tidak dapat membuka port wizard {0}: {1}" -f $Port, $_.Exception.Message)
    exit 1
}

$url = "http://127.0.0.1:$Port/"
Write-Host ''
Write-Host '======================================================' -ForegroundColor Cyan
Write-Host '  Wizard Persiapan SmartPresensi' -ForegroundColor Cyan
Write-Host '======================================================' -ForegroundColor Cyan
Write-Host ("  Buka di browser : {0}" -f $url) -ForegroundColor Green
Write-Host '  Tutup wizard setelah semua komponen siap.'
Write-Host ''

if (-not $NoBrowser) {
    try { Start-Process $url } catch { Write-Warning "Tidak dapat membuka browser otomatis: $($_.Exception.Message)" }
}

while ($script:Running) {
    $context = $null
    try {
        $context = $listener.GetContext()
        Invoke-Request -Context $context
    } catch {
        if ($context) {
            try { Send-Text -Context $context -Text 'Server error' -StatusCode 500 } catch { }
        }
        Write-Warning "Permintaan gagal: $($_.Exception.Message)"
    }
}

$listener.Stop()
$listener.Close()
Write-Host 'Wizard selesai.' -ForegroundColor Green
