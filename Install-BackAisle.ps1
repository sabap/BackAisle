#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Install BackAisle on Windows (IIS + PHP + ODBC + Python) and deploy the latest release from GitHub.

.DESCRIPTION
    Transparent installer for https://github.com/sabap/BackAisle

    What this script does:
      1. Confirms it is running elevated
      2. Resolves the latest version tag from GitHub (or uses -Version)
      3. Downloads that release ZIP (public; no token)
      4. Runs scripts\Install-BackAisle-Prereqs.ps1 to:
           - Install IIS role features for PHP FastCGI
           - Install Visual C++ Redistributable
           - Download/configure PHP NTS x64
           - Write a site-local php.ini (does not overwrite another site's php.ini)
           - Install ODBC Driver 18 for SQL Server
           - Install URL Rewrite
           - Install Python 3.12+ and pip packages
           - Point IIS Default Web Site at C:\inetpub\BackAisle\public
           - Bind HTTP :80 and HTTPS :443 (self-signed cert if needed)
      5. Optionally register collector/writer scheduled tasks
      6. Opens the web setup wizard (http://localhost/setup.php)

    What this script does NOT do:
      - Install SQL Server (use Express/Standard or SQLite in the wizard)
      - Create the database or admin user (use setup.php)

    This file is ASCII + UTF-8 BOM so Windows PowerShell 5.1 can parse it.
    Some networks intercept raw.githubusercontent.com (HTML interstitial) or
    fail GitHub TLS revocation checks (CRYPT_E_NO_REVOCATION_CHECK). Prefer
    the GitHub release asset or jsDelivr, and pass --ssl-no-revoke to curl.

      See README.md install paste (jsDelivr first; refuse HTML; require #Requires).

.PARAMETER Version
    Tag without/with v (e.g. 0.2.1) or branch main. Default: latest GitHub Release / tag.

.PARAMETER SiteRoot
    Application root. Default C:\inetpub\BackAisle

.PARAMETER HttpPort
    HTTP port for Default Web Site. Default 80.

.PARAMETER OpenSetup
    Open http://localhost/setup.php when finished.

.PARAMETER RegisterCollectorTask
    Register Task Scheduler jobs for collector.py / writer.py as SYSTEM.
#>
[CmdletBinding()]
param(
    [string]$Version = '',
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [int]$HttpPort = 80,
    [int]$HttpsPort = 443,
    [string]$SiteName = 'Default Web Site',
    [switch]$SkipHttps,
    [string]$PhpVersion = '8.3.33',
    [string]$PhpInstallPath = 'C:\PHP',
    [string]$GitHubOwner = 'sabap',
    [string]$GitHubRepo = 'BackAisle',
    [switch]$SkipOdbc,
    [switch]$SkipUrlRewrite,
    [switch]$SkipPython,
    [switch]$Force,
    [switch]$KeepDownload,
    [switch]$OpenSetup,
    [switch]$RegisterCollectorTask
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Step([string]$m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Write-Ok([string]$m) { Write-Host "    [OK] $m" -ForegroundColor Green }
function Write-Warn([string]$m) { Write-Host "    [WARN] $m" -ForegroundColor Yellow }

function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = New-Object Security.Principal.WindowsPrincipal($id)
    if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'This installer must run as Administrator.'
    }
}

function Ensure-Tls12 {
    try {
        [Net.ServicePointManager]::SecurityProtocol = `
            [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    } catch { }
    # CRL disable is not used; curl retries with --ssl-no-revoke instead.
}

function Test-BaPayload {
    param([string]$Path, [int]$MinBytes = 64, [switch]$RequireZip)
    if (-not (Test-Path -LiteralPath $Path)) { return $false }
    $len = (Get-Item -LiteralPath $Path).Length
    if ($len -lt $MinBytes) { return $false }
    $fs = [IO.File]::OpenRead($Path)
    try {
        $b = New-Object byte[] 4
        $n = $fs.Read($b, 0, 4)
        if ($n -ge 1 -and $b[0] -eq 0x3C) { return $false }
        if ($RequireZip) { return ($n -ge 2 -and $b[0] -eq 0x50 -and $b[1] -eq 0x4B) }
        return $true
    } finally { $fs.Close() }
}

function Invoke-BaDownload {
    param([string]$Uri, [string]$OutFile, [int]$MinBytes = 64, [switch]$RequireZip)
    Ensure-Tls12
    $dir = Split-Path -Parent $OutFile
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    $ua = 'BackAisle-Installer'
    $ok = $false
    if (Get-Command curl.exe -ErrorAction SilentlyContinue) {
        foreach ($revoke in @($false, $true)) {
            $curlArgs = @(
                '-fsSL', '-L', '--retry', '2', '--max-time', '120',
                '-A', $ua, '-o', $OutFile, $Uri
            )
            if ($revoke) { $curlArgs = @('--ssl-no-revoke') + $curlArgs }
            $err = Join-Path $env:TEMP ('ba-curl-{0}.err' -f [guid]::NewGuid().ToString('N'))
            try {
                $p = Start-Process -FilePath 'curl.exe' -ArgumentList $curlArgs -Wait -PassThru -NoNewWindow -RedirectStandardError $err
                if ($p.ExitCode -eq 0 -and (Test-BaPayload -Path $OutFile -MinBytes $MinBytes -RequireZip:$RequireZip)) {
                    $ok = $true
                    break
                }
            } catch { }
            finally { Remove-Item -LiteralPath $err -Force -ErrorAction SilentlyContinue }
        }
    }
    if (-not $ok) {
        try {
            Invoke-WebRequest -Uri $Uri -OutFile $OutFile -UseBasicParsing -Headers @{ 'User-Agent' = $ua }
            if (Test-BaPayload -Path $OutFile -MinBytes $MinBytes -RequireZip:$RequireZip) { $ok = $true }
        } catch {
            Write-Warn $_.Exception.Message
        }
    }
    if (-not $ok) { throw "Download failed or was not a real file (proxy HTML?): $Uri" }
}

function Get-GitHubJson([string]$Url) {
    $tmp = Join-Path $env:TEMP ("ba-gh-{0}.json" -f [guid]::NewGuid().ToString('N'))
    try {
        Invoke-BaDownload -Uri $Url -OutFile $tmp -MinBytes 2
        return (Get-Content -LiteralPath $tmp -Raw -Encoding UTF8 | ConvertFrom-Json)
    } finally {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
    }
}

function Resolve-LatestVersion {
    param([string]$Owner, [string]$Repo, [string]$Requested)
    if ($Requested) {
        $r = $Requested.Trim()
        if ($r -match '^(?i)(main|master)$') { return $r.ToLower() }
        return ($r -replace '^[vV]', '')
    }
    Write-Step "Resolving latest version from GitHub ($Owner/$Repo)"
    try {
        $rel = Get-GitHubJson "https://api.github.com/repos/$Owner/$Repo/releases/latest"
        if ($rel.tag_name) {
            $v = [string]$rel.tag_name
            Write-Ok "Latest release tag: $v"
            return ($v -replace '^[vV]', '')
        }
    } catch {
        Write-Warn "No formal GitHub Release (will use tags): $($_.Exception.Message)"
    }
    try {
        $tags = Get-GitHubJson "https://api.github.com/repos/$Owner/$Repo/tags?per_page=30"
        $best = $null
        foreach ($t in $tags) {
            $tv = ([string]$t.name) -replace '^[vV]', ''
            if ($tv -notmatch '^\d+\.\d+') { continue }
            if ($null -eq $best) { $best = $tv }
            else {
                try { if ([version]$tv -gt [version]$best) { $best = $tv } } catch { if ($tv -gt $best) { $best = $tv } }
            }
        }
        if ($best) {
            Write-Ok "Latest version tag: v$best"
            return $best
        }
    } catch {
        Write-Warn "Could not list tags: $($_.Exception.Message)"
    }
    Write-Warn 'Falling back to branch main'
    return 'main'
}

function Find-AppRoot([string]$ExtractDir) {
    if ((Test-Path (Join-Path $ExtractDir 'public\index.php')) -and (Test-Path (Join-Path $ExtractDir 'VERSION'))) {
        return $ExtractDir
    }
    foreach ($d in Get-ChildItem -Path $ExtractDir -Directory -ErrorAction SilentlyContinue) {
        if ((Test-Path (Join-Path $d.FullName 'public\index.php')) -and (Test-Path (Join-Path $d.FullName 'scripts\Install-BackAisle-Prereqs.ps1'))) {
            return $d.FullName
        }
    }
    return $null
}

function Download-Release {
    param([string]$Owner, [string]$Repo, [string]$Version, [string]$WorkRoot)
    $ref = $Version.Trim()
    $urls = @()
    if ($ref -match '^(?i)(main|master)$') {
        $label = $ref.ToLower()
        $urls += "https://codeload.github.com/$Owner/$Repo/zip/refs/heads/$label"
        $urls += "https://github.com/$Owner/$Repo/archive/refs/heads/$label.zip"
    } else {
        $ref = $ref -replace '^[vV]', ''
        $label = "v$ref"
        $urls += "https://codeload.github.com/$Owner/$Repo/zip/refs/tags/$label"
        $urls += "https://github.com/$Owner/$Repo/archive/refs/tags/$label.zip"
        $urls += "https://codeload.github.com/$Owner/$Repo/zip/refs/heads/main"
    }
    $zipPath = Join-Path $WorkRoot "BackAisle-$label.zip"
    $extractRoot = Join-Path $WorkRoot 'extract'
    Write-Step "Downloading $Owner/$Repo $label"
    $got = $false
    foreach ($zipUrl in $urls) {
        try {
            Write-Host "    Trying $zipUrl"
            Invoke-BaDownload -Uri $zipUrl -OutFile $zipPath -MinBytes 1000 -RequireZip
            $got = $true
            break
        } catch {
            Write-Warn $_.Exception.Message
        }
    }
    if (-not $got) {
        $cloneDir = Join-Path $WorkRoot 'clone'
        Write-Warn 'Zip download failed (proxy HTML?). Trying git clone.'
        $old = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            $branch = if ($label -match '^(main|master)$') { $label } else { $label }
            & git.exe clone --depth 1 --branch $branch "https://github.com/$Owner/$Repo.git" $cloneDir 2>$null
            if ($LASTEXITCODE -ne 0 -or -not (Test-Path $cloneDir)) {
                & git.exe clone --depth 1 "https://github.com/$Owner/$Repo.git" $cloneDir 2>$null
            }
        } finally { $ErrorActionPreference = $old }
        $appRoot = Find-AppRoot $cloneDir
        if ($appRoot) {
            Write-Ok "Cloned $appRoot"
            return $appRoot
        }
        throw "Could not download $Owner/$Repo $label. This network is replacing GitHub with a web page. Copy the BackAisle folder onto this server and run scripts\Install-BackAisle-Prereqs.ps1"
    }
    Write-Ok ("Downloaded {0:N0} bytes" -f (Get-Item $zipPath).Length)
    if (Test-Path $extractRoot) { Remove-Item $extractRoot -Recurse -Force }
    New-Item -ItemType Directory -Path $extractRoot -Force | Out-Null
    Expand-Archive -LiteralPath $zipPath -DestinationPath $extractRoot -Force
    $appRoot = Find-AppRoot $extractRoot
    if (-not $appRoot) { throw "Could not find BackAisle root inside $extractRoot" }
    Write-Ok "Application root: $appRoot"
    return $appRoot
}

Assert-Admin
Ensure-Tls12
Write-Host ''
Write-Host '  BackAisle installer (public GitHub release)' -ForegroundColor White
Write-Host '  https://github.com/sabap/BackAisle' -ForegroundColor DarkGray
Write-Host '  IIS Default Web Site on port 80 (fresh-server install).' -ForegroundColor DarkGray
Write-Host ''

$work = Join-Path $env:TEMP ("BackAisle-install-{0}" -f [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work -Force | Out-Null
Write-Ok "Work directory: $work"

try {
    $ver = Resolve-LatestVersion -Owner $GitHubOwner -Repo $GitHubRepo -Requested $Version
    $appRoot = Download-Release -Owner $GitHubOwner -Repo $GitHubRepo -Version $ver -WorkRoot $work
    $prereq = Join-Path $appRoot 'scripts\Install-BackAisle-Prereqs.ps1'
    if (-not (Test-Path $prereq)) {
        throw "Missing $prereq - this release is too old. Pass -Version main"
    }
    Write-Step 'Running platform + deploy script'
    $prereqArgs = @{
        PhpVersion       = $PhpVersion
        PhpInstallPath   = $PhpInstallPath
        SiteRoot         = $SiteRoot
        HttpPort         = $HttpPort
        HttpsPort        = $HttpsPort
        SiteName         = $SiteName
        DeploySource     = $appRoot
    }
    if ($SkipOdbc) { $prereqArgs.SkipOdbc = $true }
    if ($SkipUrlRewrite) { $prereqArgs.SkipUrlRewrite = $true }
    if ($SkipPython) { $prereqArgs.SkipPython = $true }
    if ($Force) { $prereqArgs.Force = $true }
    if ($OpenSetup) { $prereqArgs.OpenSetup = $true }
    if ($RegisterCollectorTask) { $prereqArgs.RegisterCollectorTask = $true }
    if ($SkipHttps) { $prereqArgs.SkipHttps = $true }
    & $prereq @prereqArgs
    if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) {
        throw "Install-BackAisle-Prereqs.ps1 exited $LASTEXITCODE"
    }
    Write-Host ''
    $next = if ($HttpPort -eq 80) { 'http://localhost/setup.php' } else { "http://localhost:${HttpPort}/setup.php" }
    Write-Host "  Next: $next" -ForegroundColor Green
    Write-Host '  Choose SQLite or SQL Server, then Fresh install, Restore backup, or PowerPanel import.'
}
finally {
    if (-not $KeepDownload -and (Test-Path $work)) {
        Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
    }
}
