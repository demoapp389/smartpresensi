<#
.SYNOPSIS
    Menyusun bundle portable SmartPresensi (Windows) tanpa perlu instalasi
    PHP / Apache / MySQL di PC tujuan.

.DESCRIPTION
    Mengunduh komponen resmi (PHP, Apache, MySQL, VC++ Redistributable),
    memverifikasi checksum bila tersedia, menyusun struktur bundle, menyalin
    aplikasi Laravel beserta connector C#, lalu menulis MANIFEST.

    Binari besar TIDAK dimasukkan ke git. Jalankan skrip ini di PC build yang
    memiliki akses internet.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\build_bundle.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\build_bundle.ps1 -OutputDir D:\dist -SkipDownload

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\build_bundle.ps1 -SkipDownload -ZipOnly
#>
[CmdletBinding()]
param(
    [string]$OutputDir,
    [string]$CacheDir,
    [int]$WebPort = 8080,
    [int]$DbPort = 3307,
    [switch]$SkipDownload,
    [switch]$SkipAppCopy,
    [switch]$Zip,
    [switch]$ZipOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($ZipOnly) { $Zip = $true }

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Templates = Join-Path $PSScriptRoot 'templates'
if (-not $OutputDir) { $OutputDir = Join-Path $RepoRoot 'dist' }
if (-not $CacheDir) { $CacheDir = Join-Path $env:TEMP 'smartpresensi-cache' }
$BundleRoot = Join-Path $OutputDir 'SmartPresensi'
$AppDir = Join-Path $BundleRoot 'app'
$RuntimeDir = Join-Path $BundleRoot 'runtime'
$DataDir = Join-Path $BundleRoot 'data'

# --- Komponen resmi (versi terkunci; ubah bila perlu) ---
$PhpVersion = '8.3.35'
$Components = @(
    [pscustomobject]@{
        Name   = 'PHP'
        Url    = "https://windows.php.net/downloads/releases/php-$PhpVersion-Win32-vs16-x64.zip"
        File   = "php-$PhpVersion-Win32-vs16-x64.zip"
        SubDir = 'php'
        Kind   = 'zip'
    }
    [pscustomobject]@{
        Name   = 'Apache'
        Url    = 'https://www.apachelounge.com/download/VS18/binaries/httpd-2.4.69-261002-Win64-VS18.zip'
        File   = 'httpd-2.4.69-261002-Win64-VS18.zip'
        SubDir = 'apache'
        Kind   = 'zip'
    }
    [pscustomobject]@{
        Name   = 'MySQL'
        Url    = 'https://cdn.mysql.com/archives/mysql-8.4/mysql-8.4.3-winx64.zip'
        File   = 'mysql-8.4.3-winx64.zip'
        SubDir = 'mysql'
        Kind   = 'zip'
    }
    [pscustomobject]@{
        Name   = 'VC++ x64'
        Url    = 'https://aka.ms/vs/17/release/vc_redist.x64.exe'
        File   = 'vc_redist.x64.exe'
        SubDir = 'vcredist'
        Kind   = 'file'
    }
    [pscustomobject]@{
        Name   = 'VC++ x86'
        Url    = 'https://aka.ms/vs/17/release/vc_redist.x86.exe'
        File   = 'vc_redist.x86.exe'
        SubDir = 'vcredist'
        Kind   = 'file'
    }
)

function Write-Step([string]$Text) {
    Write-Host ""
    Write-Host "=== $Text ===" -ForegroundColor Cyan
}

function Get-Sha256([string]$Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-RemoteFile([string]$Url, [string]$Destination) {
    $curl = Get-Command curl.exe -ErrorAction SilentlyContinue
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            Write-Host "  Mengunduh (percobaan $attempt): $Url"
            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            if ($curl) {
                & curl.exe -L --fail --retry 2 --silent --show-error --output $Destination $Url
                if ($LASTEXITCODE -ne 0) { throw "curl keluar dengan kode $LASTEXITCODE" }
            } else {
                Invoke-WebRequest -Uri $Url -OutFile $Destination -MaximumRedirection 10 -UseBasicParsing -TimeoutSec 1800
            }
            $sw.Stop()
            Write-Host ("  Selesai {0:N1} MB dalam {1:N0} dtk" -f ((Get-Item $Destination).Length / 1MB), $sw.Elapsed.TotalSeconds)
            return
        } catch {
            Write-Warning "  Gagal: $($_.Exception.Message)"
            if ($attempt -eq 3) { throw }
            Start-Sleep -Seconds 5
        }
    }
}

function Expand-Zip([string]$ZipPath, [string]$Destination) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null
    if (Test-Path -LiteralPath $Destination) { Remove-Item -LiteralPath $Destination -Recurse -Force }
    [System.IO.Compression.ZipFile]::ExtractToDirectory($ZipPath, $Destination)
}

function Get-PhpChecksum([string]$FileName) {
    $url = 'https://windows.php.net/downloads/releases/sha256sum.txt'
    try {
        $curl = Get-Command curl.exe -ErrorAction SilentlyContinue
        if ($curl) {
            $sums = & curl.exe -L --fail --silent --show-error $url
        } else {
            $sums = (Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 60).Content
        }
        $match = ($sums -split "`n") | Where-Object { $_ -match [regex]::Escape($FileName) } | Select-Object -First 1
        if ($match) { return (($match.Trim() -split '\s+')[0]).ToLowerInvariant() }
    } catch {
        Write-Warning "  Tidak dapat mengambil sha256sum.txt PHP: $($_.Exception.Message)"
    }
    return $null
}

# ---------------------------------------------------------------------------
Write-Host "SmartPresensi - Build Bundle Portable" -ForegroundColor Green
Write-Host "RepoRoot : $RepoRoot"
Write-Host "Output   : $BundleRoot"
Write-Host "Cache    : $CacheDir"
Write-Host "Web port : $WebPort   DB port: $DbPort"

New-Item -ItemType Directory -Force -Path $CacheDir, $OutputDir | Out-Null
if (Test-Path -LiteralPath $BundleRoot) {
    Write-Host "Membersihkan bundle lama..." -ForegroundColor Yellow
    Remove-Item -LiteralPath $BundleRoot -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $BundleRoot, $RuntimeDir, $DataDir | Out-Null
foreach ($d in 'mysql', 'sessions', 'logs', 'apache', 'mysql-tmp') {
    New-Item -ItemType Directory -Force -Path (Join-Path $DataDir $d) | Out-Null
}
New-Item -ItemType Directory -Force -Path (Join-Path $BundleRoot 'com') | Out-Null

# --- 1. Unduh komponen ---
Write-Step "1/5 Unduh komponen"
$downloads = @{}
foreach ($c in $Components) {
    $cached = Join-Path $CacheDir $c.File
    if ($SkipDownload -and (Test-Path -LiteralPath $cached)) {
        Write-Host "  [cache] $($c.Name): $($c.File)"
    } else {
        Get-RemoteFile -Url $c.Url -Destination $cached
    }
    $downloads[$c.Name] = $cached

    if ($c.Name -eq 'PHP') {
        $expected = Get-PhpChecksum $c.File
        if ($expected) {
            $actual = Get-Sha256 $cached
            if ($actual -ne $expected) { throw "Checksum PHP tidak cocok! expected=$expected actual=$actual" }
            Write-Host "  Checksum PHP OK" -ForegroundColor Green
        } else {
            Write-Warning "  Checksum PHP tidak diverifikasi (tidak tersedia)."
        }
    } else {
        Write-Host "  SHA256 $($c.File) = $(Get-Sha256 $cached)"
    }
}

# --- 2. Ekstrak runtime ---
Write-Step "2/5 Ekstrak runtime"
foreach ($c in $Components) {
    $src = $downloads[$c.Name]
    $target = Join-Path $RuntimeDir $c.SubDir
    if ($c.Kind -eq 'file') {
        New-Item -ItemType Directory -Force -Path $target | Out-Null
        Copy-Item -LiteralPath $src -Destination (Join-Path $target $c.File) -Force
        continue
    }
    if (Test-Path -LiteralPath $target) { Remove-Item -LiteralPath $target -Recurse -Force }
    $tmp = Join-Path $CacheDir ("_x_" + [System.IO.Path]::GetFileNameWithoutExtension($c.File))
    Expand-Zip -ZipPath $src -Destination $tmp
    # Zip resmi memiliki satu folder pembungkus (mis. Apache24/, mysql-8.4.3-winx64/);
    # PHP tidak. Bila ada tepat satu subfolder, pindahkan isinya.
    $entries = @(Get-ChildItem -LiteralPath $tmp)
    $dirs = @($entries | Where-Object { $_.PSIsContainer })
    if ($dirs.Count -eq 1) {
        # Pembungkus tunggal (mis. Apache24/, mysql-8.4.3-winx64/) -> rename
        Move-Item -LiteralPath $dirs[0].FullName -Destination $target
    } else {
        New-Item -ItemType Directory -Force -Path $target | Out-Null
        Get-ChildItem -LiteralPath $tmp | Move-Item -Destination $target
    }
    Remove-Item -LiteralPath $tmp -Recurse -Force
    Write-Host "  $($c.Name) -> $target"
}

# --- 2b. Pangkas runtime (buang file debug/statis/tidak terpakai) ---
Write-Step "2b/5 Pangkas runtime"
$script:PruneBytes = 0L
function Remove-PruneItem([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.PSIsContainer) {
        $script:PruneBytes += [int64]((Get-ChildItem -LiteralPath $Path -Recurse -Force -File -ErrorAction SilentlyContinue | Measure-Object Length -Sum).Sum)
    } else {
        $script:PruneBytes += [int64]$item.Length
    }
    Remove-Item -LiteralPath $Path -Recurse -Force -ErrorAction SilentlyContinue
}

# MySQL: PDB, static/import lib, DLL debug, kamus mecab, plugin debug, tool tak terpakai
$mysqlDir = Join-Path $RuntimeDir 'mysql'
Get-ChildItem -LiteralPath $mysqlDir -Recurse -Force -File | Where-Object { $_.Extension -eq '.pdb' -or $_.Extension -eq '.lib' } | ForEach-Object { Remove-PruneItem $_.FullName }
Get-ChildItem -LiteralPath $mysqlDir -Recurse -Force -File | Where-Object { $_.Name -match '-debug\.(dll|exe)$' } | ForEach-Object { Remove-PruneItem $_.FullName }
foreach ($d in 'include', 'docs', 'lib\mecab', 'lib\plugin\debug') { Remove-PruneItem (Join-Path $mysqlDir $d) }
Remove-PruneItem (Join-Path $mysqlDir 'lib\plugin\group_replication.dll')
$mysqlKeepTools = @('mysqld.exe', 'mysql.exe', 'mysqldump.exe', 'mysqladmin.exe', 'mysqlcheck.exe')
Get-ChildItem -LiteralPath (Join-Path $mysqlDir 'bin') -File | Where-Object { $_.Name -match '\.(exe|pl)$' -and $mysqlKeepTools -notcontains $_.Name } | ForEach-Object { Remove-PruneItem $_.FullName }

# PHP: dev/extras, tool debug, DLL tak terpakai, ekstensi tak dipakai (lihat templates\php.ini)
$phpDir = Join-Path $RuntimeDir 'php'
foreach ($d in 'dev', 'extras', 'lib') { Remove-PruneItem (Join-Path $phpDir $d) }
$phpRemoveFiles = @('php-cgi.exe', 'php-win.exe', 'phpdbg.exe', 'php8phpdbg.dll', 'php8embed.lib', 'deplister.exe', 'phar.phar.bat', 'pharcommand.phar', 'php.ini-development', 'php.ini-production', 'README.md', 'news.txt', 'snapshot.txt', 'readme-redist-bins.txt')
foreach ($f in $phpRemoveFiles) { Remove-PruneItem (Join-Path $phpDir $f) }
$phpRemoveDlls = @('glib-2.dll', 'gmodule-2.dll', 'gobject-2.dll', 'libenchant2.dll', 'libpq.dll', 'libsasl.dll')
foreach ($f in $phpRemoveDlls) { Remove-PruneItem (Join-Path $phpDir $f) }
$phpExtKeep = @('php_curl.dll', 'php_fileinfo.dll', 'php_gd.dll', 'php_intl.dll', 'php_mbstring.dll', 'php_openssl.dll', 'php_pdo_mysql.dll', 'php_zip.dll', 'php_opcache.dll', 'php_mysqli.dll', 'php_pdo_sqlite.dll', 'php_sqlite3.dll')
Get-ChildItem -LiteralPath (Join-Path $phpDir 'ext') -File -Filter 'php_*.dll' | Where-Object { $phpExtKeep -notcontains $_.Name } | ForEach-Object { Remove-PruneItem $_.FullName }

# Apache: materi dokumentasi, contoh, header build, import/lib build
$apacheDir = Join-Path $RuntimeDir 'apache'
foreach ($d in 'manual', 'htdocs', 'icons', 'cgi-bin', 'include', 'lib') { Remove-PruneItem (Join-Path $apacheDir $d) }

Write-Host ("  Runtime dipangkas: {0:N1} MB dibuang" -f ($script:PruneBytes / 1MB)) -ForegroundColor Green

# --- 3. Konfigurasi runtime (template, diisi setup.ps1) ---
Write-Step "3/5 Pasang template konfigurasi"
$tplOut = Join-Path $BundleRoot 'templates'
New-Item -ItemType Directory -Force -Path $tplOut | Out-Null
Copy-Item -Path (Join-Path $Templates '*') -Destination $tplOut -Force
# php.ini & my.ini & httpd.conf disalin juga ke lokasi default agar bisa dipakai apa adanya
Copy-Item -LiteralPath (Join-Path $Templates 'php.ini') -Destination (Join-Path $RuntimeDir 'php\php.ini') -Force
Copy-Item -LiteralPath (Join-Path $Templates 'cacert.pem') -Destination (Join-Path $RuntimeDir 'php\cacert.pem') -Force
Copy-Item -LiteralPath (Join-Path $Templates 'my.ini') -Destination (Join-Path $RuntimeDir 'mysql\my.ini') -Force
Copy-Item -LiteralPath (Join-Path $Templates 'httpd.conf') -Destination (Join-Path $RuntimeDir 'apache\conf\httpd.conf') -Force

# --- 4. Salin aplikasi Laravel ---
if (-not $SkipAppCopy) {
    Write-Step "4/5 Salin aplikasi (vendor, public/build, connector)"
    $excludeDirs = @(
        '.git', 'node_modules', 'tests', 'dist', 'deploy', 'service', 'docs', '.github',
        '.ai', '.agents', 'gentelella-master', 'PHP-soap-baru', 'runtime',
        (Join-Path 'connector' '_python_artifacts_20261016'),
        (Join-Path 'connector' 'state'),
        (Join-Path 'connector' '__pycache__'),
        (Join-Path 'storage' 'logs'),
        (Join-Path 'storage' 'framework\cache\data'),
        (Join-Path 'storage' 'framework\sessions'),
        (Join-Path 'storage' 'framework\views'),
        (Join-Path 'storage' 'app\backups'),
        (Join-Path 'storage' 'app\heartbeats'),
        (Join-Path 'storage' 'app\private'),
        (Join-Path 'storage' 'app\public'),
        (Join-Path 'public' 'storage')
    ) | ForEach-Object { Join-Path $RepoRoot $_ }
    $excludeFiles = @(
        '.env', '.env.*', '*.env', 'database.sqlite', '*.log',
        'checkpoint.txt',
        'offline_buffer*.txt',
        'python_path.txt',
        'connector_config*.ini'
    )

    New-Item -ItemType Directory -Force -Path $AppDir | Out-Null
    $rcArgs = @($RepoRoot, $AppDir, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    $rcArgs += '/XD'
    $rcArgs += $excludeDirs
    $rcArgs += '/XF'
    $rcArgs += $excludeFiles
    & robocopy @rcArgs | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy gagal dengan kode $LASTEXITCODE" }

    # Bersihkan cache/state yang tidak boleh terbawa
    foreach ($p in 'storage\logs', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views') {
        $full = Join-Path $AppDir $p
        if (Test-Path -LiteralPath $full) { Get-ChildItem -LiteralPath $full -Force | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue }
        else { New-Item -ItemType Directory -Force -Path $full | Out-Null }
    }

    # Panduan deployment dipisah per OS. Folder docs/ dikecualikan dari bundle,
    # jadi salin hanya berkas panduan yang ditampilkan halaman Dokumentasi.
    $guideDir = Join-Path $AppDir 'docs'
    New-Item -ItemType Directory -Force -Path $guideDir | Out-Null
    foreach ($guide in 'PANDUAN_DEPLOYMENT_WINDOWS.md', 'PANDUAN_DEPLOYMENT_LINUX.md') {
        $guideSrc = Join-Path $RepoRoot (Join-Path 'docs' $guide)
        if (Test-Path -LiteralPath $guideSrc) {
            Copy-Item -LiteralPath $guideSrc -Destination (Join-Path $guideDir $guide) -Force
        }
    }
    $bootCache = Join-Path $AppDir 'bootstrap\cache'
    if (Test-Path -LiteralPath $bootCache) {
        Get-ChildItem -LiteralPath $bootCache -Filter *.php -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
    }
    Get-ChildItem -Path (Join-Path $AppDir 'database') -Filter *.sqlite -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue

    # Guard: runtime development dan file sensitif tidak boleh masuk app bundle.
    $forbiddenPaths = @(
        (Join-Path $AppDir 'runtime'),
        (Join-Path $AppDir 'storage\app\backups'),
        (Join-Path $AppDir 'storage\app\heartbeats'),
        (Join-Path $AppDir 'storage\app\private'),
        (Join-Path $AppDir 'storage\app\public')
    )
    foreach ($forbidden in $forbiddenPaths) {
        if (Test-Path -LiteralPath $forbidden) {
            throw "Bundle memuat path terlarang: $forbidden"
        }
    }
    $forbiddenFiles = @(Get-ChildItem -LiteralPath $AppDir -Recurse -File -Force -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq '.env' -or $_.Name -like '.env.*' -or $_.Name -like '*.env' -or
        $_.Name -like 'connector_config*.ini' -or $_.Name -eq 'supervisor_config.ini' -or
        $_.Name -like '*.sqlite' -or $_.Name -eq 'checkpoint.txt' -or
        $_.Name -like 'offline_buffer*.txt' -or $_.Name -eq 'python_path.txt' -or
        $_.Name -like '*.log'
    })
    if ($forbiddenFiles.Count -gt 0) {
        $relative = $forbiddenFiles | ForEach-Object { $_.FullName.Substring($AppDir.Length).TrimStart('\') }
        throw ("Bundle memuat file terlarang: " + ($relative -join ', '))
    }

    # Salin service (supervisor) ke luar app agar tidak dobel
    $svcSrc = Join-Path $RepoRoot 'service'
    $svcDst = Join-Path $BundleRoot 'service'
    if (Test-Path -LiteralPath $svcSrc) {
        New-Item -ItemType Directory -Force -Path $svcDst | Out-Null
        foreach ($f in 'build_supervisor.bat') {
            $src = Join-Path $svcSrc $f
            if (Test-Path -LiteralPath $src) { Copy-Item -LiteralPath $src -Destination $svcDst -Force }
        }
        # Pilih binary supervisor terbaru (mendukung flag web_server_enabled)
        $stableExe = Join-Path $svcSrc 'SmartPresensiSupervisor.exe'
        $newExe = Join-Path $svcSrc 'SmartPresensiSupervisor_new.exe'
        $svcSourceExe = $stableExe
        if ((Test-Path -LiteralPath $newExe) -and ((-not (Test-Path -LiteralPath $stableExe)) -or ((Get-Item -LiteralPath $newExe).LastWriteTime -gt (Get-Item -LiteralPath $stableExe).LastWriteTime))) {
            $svcSourceExe = $newExe
            Write-Host "  Supervisor: memakai SmartPresensiSupervisor_new.exe (lebih baru)."
        }
        if (Test-Path -LiteralPath $svcSourceExe) {
            Copy-Item -LiteralPath $svcSourceExe -Destination (Join-Path $svcDst 'SmartPresensiSupervisor.exe') -Force
        } else {
            Write-Warning "  Executable supervisor tidak ditemukan di service\."
        }
    }

    # COM DLL (bila operator menaruhnya di deploy\com\zkemkeeper.dll)
    $comSrc = Join-Path $PSScriptRoot 'com\zkemkeeper.dll'
    if (Test-Path -LiteralPath $comSrc) {
        Copy-Item -LiteralPath $comSrc -Destination (Join-Path $BundleRoot 'com\zkemkeeper.dll') -Force
        Write-Host "  zkemkeeper.dll disertakan."
    } else {
        Write-Warning "  zkemkeeper.dll TIDAK ditemukan di deploy\com\. Letakkan file 32-bit di sana agar ikut dibundel."
    }
} else {
    Write-Host "  [skip] penyalinan aplikasi"
}

# --- 5. Skrip instalasi + manifest ---
Write-Step "5/5 Salin skrip setup & tulis manifest"
Copy-Item -Path (Join-Path $PSScriptRoot 'setup.ps1'), (Join-Path $PSScriptRoot 'setup.cmd'), `
    (Join-Path $PSScriptRoot 'repair.ps1'), (Join-Path $PSScriptRoot 'backup.ps1'), `
    (Join-Path $PSScriptRoot 'restore.ps1'), (Join-Path $PSScriptRoot 'start-setup.ps1') `
    -Destination $BundleRoot -Force -ErrorAction SilentlyContinue

# Bootstrap Web-Based Setup Assistant (dibangun via deploy\setup-assistant\build.ps1)
$assistantExe = Join-Path $PSScriptRoot 'setup-assistant\setup-assistant.exe'
if (Test-Path -LiteralPath $assistantExe) {
    Copy-Item -LiteralPath $assistantExe -Destination (Join-Path $BundleRoot 'setup-assistant.exe') -Force
    Write-Host '  setup-assistant.exe disertakan.'
} else {
    Write-Warning '  setup-assistant.exe tidak ada. Jalankan deploy\setup-assistant\build.ps1 lebih dulu.'
}

# Step engine (dipanggil oleh wizard)
$stepsSrc = Join-Path $PSScriptRoot 'steps\setup-steps.ps1'
if (Test-Path -LiteralPath $stepsSrc) {
    $stepsDir = Join-Path $BundleRoot 'steps'
    New-Item -ItemType Directory -Path $stepsDir -Force | Out-Null
    Copy-Item -LiteralPath $stepsSrc -Destination (Join-Path $stepsDir 'setup-steps.ps1') -Force
    Write-Host '  steps\setup-steps.ps1 disertakan.'
} else {
    Write-Warning '  steps\setup-steps.ps1 tidak ada.'
}

$manifest = @()
$manifest += "SmartPresensi Bundle Manifest"
$manifest += "Dibuat        : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
$manifest += "PHP           : $PhpVersion (Win32-vs16-x64, Thread Safe)  sha256=$(Get-Sha256 $downloads['PHP'])"
$manifest += "Apache        : 2.4.69-261002-Win64-VS18  sha256=$(Get-Sha256 $downloads['Apache'])"
$manifest += "MySQL         : 8.4.3-winx64  sha256=$(Get-Sha256 $downloads['MySQL'])"
$manifest += "VC++ x64      : sha256=$(Get-Sha256 $downloads['VC++ x64'])"
$manifest += "VC++ x86      : sha256=$(Get-Sha256 $downloads['VC++ x86'])"
$manifest += "Web port      : $WebPort"
$manifest += "DB port       : $DbPort"
$manifest += "Runtime       : dipangkas (buang *.pdb, *.lib, debug, mecab, tool tak terpakai)"
$manifest += "Setup wizard  : " + $(if (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'setup-assistant\setup-assistant.exe')) { 'setup-assistant.exe (Go + Tailwind)' } else { 'TIDAK DISERTAKAN' })
$manifest | Set-Content -LiteralPath (Join-Path $BundleRoot 'MANIFEST.txt') -Encoding UTF8

# --- 6. Arsip ZIP distribusi (opsional) ---
$zipPath = Join-Path $OutputDir 'SmartPresensi.zip'
if ($Zip) {
    Write-Step "6/6 Buat arsip ZIP distribusi"
    Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null
    if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
    [System.IO.Compression.ZipFile]::CreateFromDirectory($BundleRoot, $zipPath, [System.IO.Compression.CompressionLevel]::Optimal, $true)

    $zipArchive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $sensitiveEntries = @($zipArchive.Entries | Where-Object {
            ($_.FullName -replace '\\', '/') -match '(^|/)(app/runtime|mysql/data|storage/app/backups|storage/app/heartbeats|storage/app/private|storage/app/public)/' -or
            $_.Name -eq '.env' -or $_.Name -like '.env.*' -or $_.Name -like '*.env' -or
            $_.Name -like 'connector_config*.ini' -or $_.Name -eq 'supervisor_config.ini' -or
            $_.Name -like '*.sqlite' -or $_.Name -eq 'checkpoint.txt' -or
            $_.Name -like 'offline_buffer*.txt' -or $_.Name -eq 'python_path.txt' -or
            $_.Name -like '*.log'
        })
        if ($sensitiveEntries.Count -gt 0) {
            Remove-Item -LiteralPath $zipPath -Force
            throw ("ZIP memuat entri terlarang: " + (($sensitiveEntries | ForEach-Object { $_.FullName }) -join ', '))
        }
    } finally {
        $zipArchive.Dispose()
    }

    Write-Host ("  ZIP: {0} ({1:N1} MB)" -f $zipPath, ((Get-Item $zipPath).Length / 1MB)) -ForegroundColor Green
}

if ($ZipOnly) {
    Remove-Item -LiteralPath $BundleRoot -Recurse -Force
    Write-Host ""
    Write-Host "SELESAI. ZIP: $zipPath" -ForegroundColor Green
} else {
    Write-Host ""
    Write-Host "SELESAI. Bundle: $BundleRoot" -ForegroundColor Green
    Get-ChildItem -LiteralPath $BundleRoot | Select-Object Name, Mode | Format-Table -AutoSize
}
