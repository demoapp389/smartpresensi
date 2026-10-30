# ---------------------------------------------------------------------------
# SmartPresensi - Fetch (unduh + verifikasi + ekstrak satu komponen)
#
# Dipanggil oleh scripts\setup-server.ps1 (wizard) atau manual:
#   powershell -ExecutionPolicy Bypass -File scripts\fetch.ps1 -Component python
#   ... -Component php -LocalFile D:\unduhan\php-8.3.35-Win32-vs16-x64.zip
#
# Menulis progres ke scripts\state\progress-<id>.json (dibaca wizard).
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Component,
    [string]$Manifest,
    [string]$CacheDir,
    [string]$RuntimeDir,
    [string]$ProgressPath,
    [string]$LocalFile,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

try { Add-Type -AssemblyName System.Net.Http -ErrorAction Stop | Out-Null } catch { }

# Fungsi pembuat konfigurasi runtime dibagikan lewat runtime.ps1 agar path selalu
# mengikuti lokasi proyek saat ini (portabel bila folder dipindah/dikopi).
. (Join-Path $PSScriptRoot 'runtime.ps1')

$ProjectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if (-not $Manifest) { $Manifest = Join-Path $PSScriptRoot 'components.json' }
if (-not $RuntimeDir) { $RuntimeDir = Join-Path $ProjectRoot 'runtime' }
if (-not $CacheDir) { $CacheDir = Join-Path (Join-Path $RuntimeDir '_cache') '' }
$stateDir = Join-Path $PSScriptRoot 'state'
if (-not $ProgressPath) { $ProgressPath = Join-Path $stateDir ("progress-{0}.json" -f $Component) }
New-Item -ItemType Directory -Force -Path $RuntimeDir, $CacheDir, $stateDir | Out-Null

function Get-Prop {
    param($Object, [string]$Name, $Default = $null)
    if ($null -eq $Object) { return $Default }
    $p = $Object.PSObject.Properties[$Name]
    if ($p -and $null -ne $p.Value) { return $p.Value }
    return $Default
}

function Set-Progress {
    param([ValidateSet('running', 'done', 'error')][string]$Status, [int]$Percent, [string]$Message)
    $obj = [ordered]@{
        component = $Component
        status    = $Status
        percent   = $Percent
        message   = $Message
        time      = (Get-Date).ToString('o')
    }
    try {
        [System.IO.File]::WriteAllText($ProgressPath, ($obj | ConvertTo-Json -Compress), (New-Object System.Text.UTF8Encoding($false)))
    } catch {
        Write-Warning "Tidak dapat menulis progres: $($_.Exception.Message)"
    }
}

function New-HttpClient {
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
    $client = New-Object System.Net.Http.HttpClient
    $client.Timeout = [TimeSpan]::FromMinutes(180)
    $client.DefaultRequestHeaders.UserAgent.ParseAdd('SmartPresensi-Setup/1.0')
    return $client
}

function Invoke-Download {
    param([Parameter(Mandatory)][string]$Url, [Parameter(Mandatory)][string]$Destination, [Parameter(Mandatory)][string]$Label)
    $tmp = "$Destination.part"
    if (Test-Path -LiteralPath $tmp) { Remove-Item -LiteralPath $tmp -Force }
    $client = New-HttpClient
    try {
        $resp = $client.GetAsync($Url, [System.Net.Http.HttpCompletionOption]::ResponseHeadersRead).GetAwaiter().GetResult()
        if (-not $resp.IsSuccessStatusCode) {
            throw ("HTTP {0} {1}" -f [int]$resp.StatusCode, $resp.ReasonPhrase)
        }
        $total = $resp.Content.Headers.ContentLength
        $totalMb = if ($total) { $total / 1MB } else { 0 }
        $inStream = $resp.Content.ReadAsStreamAsync().GetAwaiter().GetResult()
        $outStream = [System.IO.File]::Create($tmp)
        try {
            $buffer = New-Object byte[] 1048576
            $sum = 0L
            $lastReport = [DateTime]::UtcNow
            while ($true) {
                $read = $inStream.Read($buffer, 0, $buffer.Length)
                if ($read -le 0) { break }
                $outStream.Write($buffer, 0, $read)
                $sum += $read
                if ((([DateTime]::UtcNow) - $lastReport).TotalMilliseconds -ge 400) {
                    $lastReport = [DateTime]::UtcNow
                    $pct = if ($total -gt 0) { [int](($sum * 100) / $total) } else { 0 }
                    Set-Progress 'running' $pct ("Mengunduh {0} - {1:N1}/{2:N1} MB" -f $Label, ($sum / 1MB), $totalMb)
                }
            }
        } finally {
            $outStream.Dispose()
            $inStream.Dispose()
        }
        if (Test-Path -LiteralPath $Destination) { Remove-Item -LiteralPath $Destination -Force }
        Move-Item -LiteralPath $tmp -Destination $Destination -Force
    } finally {
        $client.Dispose()
    }
}

function Get-RemoteText {
    param([Parameter(Mandatory)][string]$Url)
    $client = New-HttpClient
    try {
        return $client.GetStringAsync($Url).GetAwaiter().GetResult()
    } finally {
        $client.Dispose()
    }
}

function Get-ExpectedSha256 {
    param($Comp)
    $sha = (Get-Prop $Comp 'sha256' '').Trim()
    if ($sha) { return $sha.ToLowerInvariant() }
    $sumUrl = (Get-Prop $Comp 'checksumUrl' '').Trim()
    if (-not $sumUrl) { return '' }
    $fileName = Get-Prop $Comp 'file' ''
    Set-Progress 'running' 1 'Mengambil checksum resmi...'
    try {
        $text = Get-RemoteText -Url $sumUrl
        $line = ($text -split "`n") | Where-Object { $_ -match [regex]::Escape($fileName) } | Select-Object -First 1
        if ($line) { return (($line.Trim() -split '\s+')[0]).ToLowerInvariant() }
    } catch {
        Write-Warning "Tidak dapat mengambil checksum: $($_.Exception.Message)"
    }
    return ''
}

function Test-Sha256 {
    param([Parameter(Mandatory)][string]$Path, [string]$Expected)
    if (-not $Expected) { return $true }
    $actual = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $Expected) {
        throw ("Checksum tidak cocok. File mungkin rusak.`r`n  diharapkan : {0}`r`n  sebenarnya : {1}" -f $Expected, $actual)
    }
    return $true
}

function Expand-Component {
    param([Parameter(Mandatory)][string]$ZipPath, [Parameter(Mandatory)][string]$SubDir)
    Set-Progress 'running' 95 'Mengekstrak...'
    Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null
    $target = Join-Path $RuntimeDir $SubDir
    $tmp = Join-Path $CacheDir ("_x_{0}_{1}" -f $SubDir, ([guid]::NewGuid().ToString('N').Substring(0, 8)))
    if (Test-Path -LiteralPath $tmp) { Remove-Item -LiteralPath $tmp -Recurse -Force }
    [System.IO.Compression.ZipFile]::ExtractToDirectory($ZipPath, $tmp)

    if (Test-Path -LiteralPath $target) { Remove-Item -LiteralPath $target -Recurse -Force }
    $entries = @(Get-ChildItem -LiteralPath $tmp)
    $dirs = @($entries | Where-Object { $_.PSIsContainer })
    if ($entries.Count -eq 1 -and $dirs.Count -eq 1) {
        Move-Item -LiteralPath $dirs[0].FullName -Destination $target
    } else {
        New-Item -ItemType Directory -Force -Path $target | Out-Null
        Get-ChildItem -LiteralPath $tmp | Move-Item -Destination $target
    }
    Remove-Item -LiteralPath $tmp -Recurse -Force
    return $target
}

function Install-Executable {
    param([Parameter(Mandatory)][string]$ExePath, $Comp)
    $argString = Get-Prop $Comp 'installArgs' '/install /quiet /norestart'
    Set-Progress 'running' 95 'Memasang...'
    $p = Start-Process -FilePath $ExePath -ArgumentList $argString -Wait -PassThru
    if ($p.ExitCode -notin @(0, 1638, 3010, 1641)) {
        throw ("Pemasang keluar dengan kode {0}" -f $p.ExitCode)
    }
}

function Install-PhpIni {
    Set-Progress 'running' 98 'Menulis php.ini...'
    New-SPPhpIni -ProjectRoot $ProjectRoot | Out-Null
}

function Install-MysqlInit {
    $mysqlDir = Join-Path $RuntimeDir 'mysql'
    $mysqld = Join-Path $mysqlDir 'bin\mysqld.exe'
    if (-not (Test-Path -LiteralPath $mysqld)) { return }
    $dataDir = Join-Path $mysqlDir 'data'
    $iniPath = Join-Path $mysqlDir 'my.ini'
    New-SPMysqlIni -ProjectRoot $ProjectRoot | Out-Null

    if (Test-Path -LiteralPath (Join-Path $dataDir 'mysql')) {
        Set-Progress 'running' 98 'Data MySQL sudah diinisialisasi (dilewati).'
        return
    }
    Set-Progress 'running' 97 'Menginisialisasi database MySQL...'
    New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
    $out = & $mysqld "--defaults-file=$iniPath" '--initialize-insecure' 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) {
        throw ("Inisialisasi MySQL gagal:`r`n{0}" -f $out)
    }
}

function Install-PythonPth {
    Update-SPPythonPth -ProjectRoot $ProjectRoot | Out-Null
}

# ---------------------------------------------------------------------------
try {
    Set-Progress 'running' 0 'Membaca manifes...'
    if (-not (Test-Path -LiteralPath $Manifest)) { throw "Manifes tidak ditemukan: $Manifest" }
    $manifestData = Get-Content -Raw -LiteralPath $Manifest | ConvertFrom-Json
    $comp = $manifestData.components | Where-Object { $_.id -eq $Component } | Select-Object -First 1
    if (-not $comp) { throw "Komponen '$Component' tidak ada di manifes." }

    $name = Get-Prop $comp 'name' $Component
    $kind = Get-Prop $comp 'kind' 'zip'
    if ($kind -eq 'external') {
        Set-Progress 'error' 0 ("Komponen '{0}' harus diunduh manual dari situs resmi." -f $name)
        exit 3
    }

    $fileName = Get-Prop $comp 'file' ''
    $dest = Join-Path $CacheDir $fileName
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null

    if ($LocalFile) {
        if (-not (Test-Path -LiteralPath $LocalFile)) { throw "File lokal tidak ditemukan: $LocalFile" }
        Set-Progress 'running' 5 'Menyalin file lokal...'
        Copy-Item -LiteralPath $LocalFile -Destination $dest -Force
    } elseif ((Test-Path -LiteralPath $dest) -and -not $Force) {
        Set-Progress 'running' 5 'Memakai file unduhan yang sudah ada (cache).'
    } else {
        Set-Progress 'running' 2 ("Mengunduh {0}..." -f $name)
        Invoke-Download -Url (Get-Prop $comp 'url') -Destination $dest -Label $name
    }

    $expected = Get-ExpectedSha256 -Comp $comp
    if ($expected) {
        Set-Progress 'running' 90 'Memverifikasi checksum...'
        Test-Sha256 -Path $dest -Expected $expected | Out-Null
    }

    if ($kind -eq 'zip') {
        Expand-Component -ZipPath $dest -SubDir (Get-Prop $comp 'subdir') | Out-Null
    } elseif ($kind -eq 'exe') {
        $target = Join-Path $RuntimeDir (Get-Prop $comp 'subdir' '')
        New-Item -ItemType Directory -Force -Path $target | Out-Null
        Copy-Item -LiteralPath $dest -Destination (Join-Path $target $fileName) -Force
        Install-Executable -ExePath $dest -Comp $comp
    }

    switch (Get-Prop $comp 'postInstall' '') {
        'php-ini' { Install-PhpIni }
        'mysql-init' { Install-MysqlInit }
        'python-pth' { Install-PythonPth }
    }

    Set-Progress 'done' 100 ("{0} siap." -f $name)
    exit 0
} catch {
    Set-Progress 'error' 0 $_.Exception.Message
    Write-Error $_.Exception.Message
    exit 1
}
