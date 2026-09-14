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
           - Create IIS site **BackAisle** on port 8080 (never Default Web Site)
      5. Optionally register collector/writer scheduled tasks
      6. Opens the web setup wizard (setup.php)

    What this script does NOT do:
      - Install SQL Server (use Express/Standard or SQLite in the wizard)
      - Create the database or admin user (use setup.php)
      - Modify ColdAisle / Default Web Site / port 80

    Recommended (elevated PowerShell, not from C:\Windows\system32).
    Prefer the GitHub release asset or jsDelivr — some networks replace
    raw.githubusercontent.com with an HTML interstitial:

      $out = Join-Path $env:TEMP 'Install-BackAisle.ps1'
      curl.exe -fsSL -o $out https://github.com/sabap/BackAisle/releases/latest/download/Install-BackAisle.ps1
      Get-Content $out -TotalCount 1   # must be: #Requires -RunAsAdministrator
      Set-ExecutionPolicy Bypass -Scope Process -Force
      & $out -OpenSetup

.PARAMETER Version
    Tag without/with v (e.g. 0.2.0) or branch main. Default: latest GitHub Release / tag.

.PARAMETER SiteRoot
    Application root. Default C:\inetpub\BackAisle

.PARAMETER HttpPort
    IIS binding. Default 8080

.PARAMETER OpenSetup
    Open http://localhost:8080/setup.php when finished.

.PARAMETER RegisterCollectorTask
    Register Task Scheduler jobs for collector.py / writer.py as SYSTEM.
#>
[CmdletBinding()]
param(
    [string]$Version = '',
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [int]$HttpPort = 8080,
    [string]$PhpVersion = '8.3.32',
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
}

function Get-GitHubJson([string]$Url) {
    $headers = @{ Accept = 'application/vnd.github+json'; 'User-Agent' = 'BackAisle-Installer' }
    return Invoke-RestMethod -Uri $Url -Headers $headers -UseBasicParsing
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
    if (-not $best) { throw "No version tags on $Owner/$Repo. Push v0.1.0 first." }
    Write-Ok "Latest version tag: v$best"
    return $best
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
    if ($ref -match '^(?i)(main|master)$') {
        $zipUrl = "https://github.com/$Owner/$Repo/archive/refs/heads/$($ref.ToLower()).zip"
        $label = $ref.ToLower()
    } else {
        $ref = $ref -replace '^[vV]', ''
        $zipUrl = "https://github.com/$Owner/$Repo/archive/refs/tags/v$ref.zip"
        $label = "v$ref"
    }
    $zipPath = Join-Path $WorkRoot "BackAisle-$label.zip"
    $extractRoot = Join-Path $WorkRoot 'extract'
    Write-Step "Downloading $Owner/$Repo $label"
    Invoke-WebRequest -Uri $zipUrl -OutFile $zipPath -UseBasicParsing
    if (-not (Test-Path $zipPath) -or ((Get-Item $zipPath).Length -lt 1000)) {
        throw "Download failed: $zipPath"
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
Write-Host '  Does not modify Default Web Site or ColdAisle.' -ForegroundColor DarkGray
Write-Host ''

$work = Join-Path $env:TEMP ("BackAisle-install-{0}" -f [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work -Force | Out-Null
Write-Ok "Work directory: $work"

try {
    $ver = Resolve-LatestVersion -Owner $GitHubOwner -Repo $GitHubRepo -Requested $Version
    $appRoot = Download-Release -Owner $GitHubOwner -Repo $GitHubRepo -Version $ver -WorkRoot $work
    $prereq = Join-Path $appRoot 'scripts\Install-BackAisle-Prereqs.ps1'
    if (-not (Test-Path $prereq)) {
        throw "Missing $prereq — this release is too old. Pass -Version main"
    }
    Write-Step 'Running platform + deploy script'
    $args = @{
        PhpVersion       = $PhpVersion
        PhpInstallPath   = $PhpInstallPath
        SiteRoot         = $SiteRoot
        HttpPort         = $HttpPort
        DeploySource     = $appRoot
    }
    if ($SkipOdbc) { $args.SkipOdbc = $true }
    if ($SkipUrlRewrite) { $args.SkipUrlRewrite = $true }
    if ($SkipPython) { $args.SkipPython = $true }
    if ($Force) { $args.Force = $true }
    if ($OpenSetup) { $args.OpenSetup = $true }
    if ($RegisterCollectorTask) { $args.RegisterCollectorTask = $true }
    & $prereq @args
    if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) {
        throw "Install-BackAisle-Prereqs.ps1 exited $LASTEXITCODE"
    }
    Write-Host ''
    Write-Host "  Next: http://localhost:$HttpPort/setup.php" -ForegroundColor Green
    Write-Host '  Choose SQLite or SQL Server, then Fresh install, Restore backup, or PowerPanel import.'
}
finally {
    if (-not $KeepDownload -and (Test-Path $work)) {
        Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
    }
}
