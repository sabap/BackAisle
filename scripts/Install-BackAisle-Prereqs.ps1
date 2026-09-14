#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs IIS + PHP + ODBC + Python prerequisites for BackAisle and deploys the app.

.DESCRIPTION
    - IIS role services for PHP FastCGI
    - Visual C++ Redistributable x64
    - PHP NTS x64 (if missing) plus a *site-local* php.ini (does not overwrite another site's php.ini)
    - ODBC Driver 18 for SQL Server (setup wizard)
    - IIS URL Rewrite
    - Python 3.12+ and pip packages (pysnmp, cryptography, paramiko, pyodbc)
    - IIS site **BackAisle** on port 8080 (never Default Web Site / :80)
    - NTFS grants for the BackAisle app pool

    Does NOT install SQL Server. Use setup.php to connect SQLite or an existing SQL instance.

.PARAMETER PhpVersion
    PHP NTS build on windows.php.net. Default 8.3.32

.PARAMETER PhpInstallPath
    Shared PHP binaries. Default C:\PHP

.PARAMETER SiteRoot
    Application root. Default C:\inetpub\BackAisle

.PARAMETER HttpPort
    IIS site port. Default 8080 (leave 80 for other sites)

.PARAMETER DeploySource
    Source tree to copy. Default: parent of this script.

.PARAMETER SkipOdbc / SkipUrlRewrite / SkipPython / SkipDeploy / Force / OpenSetup / RegisterCollectorTask
#>
[CmdletBinding()]
param(
    [string]$PhpVersion = '8.3.32',
    [string]$PhpInstallPath = 'C:\PHP',
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [int]$HttpPort = 8080,
    [string]$SiteName = 'BackAisle',
    [string]$PoolName = 'BackAisle',
    [string]$DeploySource = '',
    [switch]$SkipOdbc,
    [switch]$SkipUrlRewrite,
    [switch]$SkipPython,
    [switch]$SkipDeploy,
    [switch]$Force,
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
        throw 'Run elevated PowerShell (Administrator).'
    }
}

function Ensure-Tls12 {
    try {
        [Net.ServicePointManager]::SecurityProtocol = `
            [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    } catch { }
}

function Test-CommandExists([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Download-File([string]$Uri, [string]$OutFile) {
    Ensure-Tls12
    Write-Host "    Downloading: $Uri"
    if ((Test-Path $OutFile) -and -not $Force) {
        Write-Ok "Already present: $OutFile"
        return
    }
    $dir = Split-Path -Parent $OutFile
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    if (Test-CommandExists 'curl.exe') {
        & curl.exe -fsSL -L --retry 3 -o $OutFile $Uri
        if ($LASTEXITCODE -ne 0) { throw "curl download failed: $Uri" }
    } else {
        Invoke-WebRequest -Uri $Uri -OutFile $OutFile -UseBasicParsing
    }
    if (-not (Test-Path $OutFile) -or ((Get-Item $OutFile).Length -lt 1024)) {
        throw "Download failed or file too small: $OutFile"
    }
}

function Set-IniValue {
    param([string]$Content, [string]$Name, [string]$Value, [switch]$IsExtension)
    if ($IsExtension) {
        foreach ($n in @($Name, "php_$Name.dll", "php_$Name")) {
            $pattern = "(?m)^\s*;?\s*extension\s*=\s*(`"?)$([regex]::Escape($n))(`"?)\s*$"
            if ($Content -match $pattern) {
                return [regex]::Replace($Content, $pattern, "extension=$Name", 1)
            }
        }
        return $Content.TrimEnd() + "`r`nextension=$Name`r`n"
    }
    $pattern = "(?m)^\s*;?\s*$([regex]::Escape($Name))\s*=.*$"
    if ($Content -match $pattern) {
        return [regex]::Replace($Content, $pattern, "$Name = $Value", 1)
    }
    return $Content.TrimEnd() + "`r`n$Name = $Value`r`n"
}

function Install-IisFeatures {
    Write-Step 'Installing IIS role services'
    $features = @(
        'Web-Server','Web-Common-Http','Web-Default-Doc','Web-Dir-Browsing','Web-Http-Errors',
        'Web-Static-Content','Web-Http-Logging','Web-Filtering','Web-Stat-Compression','Web-CGI','Web-Mgmt-Console'
    )
    if (Test-CommandExists 'Install-WindowsFeature') {
        $r = Install-WindowsFeature -Name $features -IncludeManagementTools
        if ($r.RestartNeeded -eq 'Yes') { Write-Warn 'A reboot may be required after feature install.' }
        Write-Ok 'IIS features installed (Server)'
    } elseif (Test-CommandExists 'Enable-WindowsOptionalFeature') {
        foreach ($f in @(
            'IIS-WebServerRole','IIS-WebServer','IIS-CommonHttpFeatures','IIS-DefaultDocument',
            'IIS-DirectoryBrowsing','IIS-HttpErrors','IIS-StaticContent','IIS-HttpLogging',
            'IIS-RequestFiltering','IIS-HttpCompressionStatic','IIS-CGI','IIS-ManagementConsole'
        )) {
            Enable-WindowsOptionalFeature -Online -FeatureName $f -All -NoRestart | Out-Null
        }
        Write-Ok 'IIS features enabled (Client OS)'
    } else {
        throw 'Cannot install IIS (Install-WindowsFeature / Enable-WindowsOptionalFeature missing).'
    }
    Import-Module WebAdministration -ErrorAction Stop
}

function Install-VcRedist {
    Write-Step 'Visual C++ Redistributable (x64 2015-2022)'
    $regPaths = @(
        'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\X64'
    )
    $installed = $false
    foreach ($rp in $regPaths) {
        if (Test-Path $rp) {
            $maj = (Get-ItemProperty $rp -ErrorAction SilentlyContinue).Major
            if ($maj -ge 14) { $installed = $true; break }
        }
    }
    if ($installed -and -not $Force) { Write-Ok 'VC++ Redistributable already installed'; return }
    $vcFile = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Download-File -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vcFile
    $p = Start-Process -FilePath $vcFile -ArgumentList '/install','/quiet','/norestart' -Wait -PassThru
    if ($p.ExitCode -notin 0, 1638, 3010) {
        Write-Warn "VC++ installer exit $($p.ExitCode)"
    } else {
        Write-Ok "VC++ Redistributable installed (exit $($p.ExitCode))"
    }
}

function Get-PhpDownloadUrl([string]$Version) {
    $candidates = @(
        "https://windows.php.net/downloads/releases/php-$Version-nts-Win32-vs16-x64.zip",
        "https://windows.php.net/downloads/releases/php-$Version-nts-Win32-vs17-x64.zip",
        "https://windows.php.net/downloads/releases/archives/php-$Version-nts-Win32-vs16-x64.zip",
        "https://windows.php.net/downloads/releases/archives/php-$Version-nts-Win32-vs17-x64.zip"
    )
    Ensure-Tls12
    foreach ($url in $candidates) {
        try {
            $resp = Invoke-WebRequest -Uri $url -Method Head -UseBasicParsing -TimeoutSec 20
            if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 400) { return $url }
        } catch { }
    }
    throw "Could not find PHP $Version NTS x64 on windows.php.net. Pass -PhpVersion from https://windows.php.net/download/"
}

function Install-Php {
    Write-Step "PHP $PhpVersion NTS x64 (shared binaries at $PhpInstallPath)"
    $phpCgi = Join-Path $PhpInstallPath 'php-cgi.exe'
    if ((Test-Path $phpCgi) -and -not $Force) {
        Write-Ok "PHP already present at $PhpInstallPath (will not overwrite another site's php.ini)"
    } else {
        $url = Get-PhpDownloadUrl -Version $PhpVersion
        $zip = Join-Path $env:TEMP "php-$PhpVersion-nts-x64.zip"
        Download-File -Uri $url -OutFile $zip
        New-Item -ItemType Directory -Path $PhpInstallPath -Force | Out-Null
        Expand-Archive -Path $zip -DestinationPath $PhpInstallPath -Force
        Write-Ok "PHP extracted to $PhpInstallPath"
    }
    if (-not (Test-Path $phpCgi)) { throw "php-cgi.exe missing under $PhpInstallPath" }
}

function Write-SitePhpIni {
    Write-Step 'Site-local php.ini for BackAisle (does not change global php.ini)'
    $prod = Join-Path $PhpInstallPath 'php.ini-production'
    $ini = Join-Path $SiteRoot 'php.ini'
    if (-not (Test-Path $prod)) { throw "php.ini-production missing in $PhpInstallPath" }
    if ((Test-Path $ini) -and -not $Force) {
        Write-Ok "Keeping existing $ini"
        return $ini
    }
    $c = Get-Content -Path $prod -Raw
    $c = Set-IniValue $c 'extension_dir' "`"$PhpInstallPath\ext`""
    $c = Set-IniValue $c 'cgi.fix_path_info' '1'
    $c = Set-IniValue $c 'fastcgi.impersonate' '1'
    $c = Set-IniValue $c 'date.timezone' 'UTC'
    $c = Set-IniValue $c 'expose_php' 'Off'
    $c = Set-IniValue $c 'display_errors' 'Off'
    $c = Set-IniValue $c 'log_errors' 'On'
    $c = Set-IniValue $c 'error_log' "`"$SiteRoot\logs\php-error.log`""
    $c = Set-IniValue $c 'upload_max_filesize' '64M'
    $c = Set-IniValue $c 'post_max_size' '64M'
    $c = Set-IniValue $c 'memory_limit' '256M'
    $c = Set-IniValue $c 'max_execution_time' '180'
    $winTemp = Join-Path $env:SystemRoot 'Temp'
    $c = Set-IniValue $c 'session.save_path' "`"$winTemp`""
    $c = Set-IniValue $c 'sys_temp_dir' "`"$winTemp`""
    foreach ($ext in @('curl','mbstring','openssl','fileinfo','ldap','pdo_sqlite','sqlite3','pdo_odbc','zip')) {
        $c = Set-IniValue $c $ext -IsExtension
    }
    foreach ($ext in @('pdo_sqlsrv','sqlsrv')) {
        $dll = Join-Path $PhpInstallPath "ext\php_$ext.dll"
        if (Test-Path $dll) { $c = Set-IniValue $c $ext -IsExtension }
    }
    New-Item -ItemType Directory -Path (Join-Path $SiteRoot 'logs') -Force | Out-Null
    Set-Content -Path $ini -Value $c -Encoding ASCII
    Write-Ok "Wrote $ini"
    return $ini
}

function Install-Odbc18 {
    if ($SkipOdbc) { Write-Warn 'Skipping ODBC Driver 18'; return }
    Write-Step 'ODBC Driver 18 for SQL Server'
    $name = Get-OdbcDriver -Name 'ODBC Driver 18 for SQL Server' -ErrorAction SilentlyContinue
    if ($name -and -not $Force) { Write-Ok 'ODBC Driver 18 already installed'; return }
    $msi = Join-Path $env:TEMP 'msodbcsql18.msi'
    $url = 'https://go.microsoft.com/fwlink/?linkid=2249006'
    try {
        Download-File -Uri $url -OutFile $msi
        Start-Process msiexec.exe -ArgumentList "/i `"$msi`" IACCEPTMSODBCSQLLICENSETERMS=YES /qn /norestart" -Wait
        Write-Ok 'ODBC Driver 18 installed'
    } catch {
        Write-Warn "ODBC Driver 18 install skipped: $($_.Exception.Message)"
    }
}

function Install-UrlRewrite {
    if ($SkipUrlRewrite) { Write-Warn 'Skipping URL Rewrite'; return }
    Write-Step 'IIS URL Rewrite'
    $key = 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite'
    if ((Test-Path $key) -and -not $Force) { Write-Ok 'URL Rewrite already installed'; return }
    $msi = Join-Path $env:TEMP 'rewrite_amd64_en-US.msi'
    Download-File -Uri 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi' -OutFile $msi
    Start-Process msiexec.exe -ArgumentList "/i `"$msi`" /qn /norestart" -Wait
    Write-Ok 'URL Rewrite installed'
}

function Install-Python {
    if ($SkipPython) { Write-Warn 'Skipping Python'; return }
    Write-Step 'Python 3.12+'
    $py = $null
    $cmd = Get-Command python -ErrorAction SilentlyContinue
    if ($cmd) { $py = $cmd.Source }
    foreach ($c in @("$env:ProgramFiles\Python312\python.exe", "$env:LocalAppData\Programs\Python\Python312\python.exe")) {
        if (-not $py -and (Test-Path $c)) { $py = $c }
    }
    if ($py -and -not $Force) {
        Write-Ok "Python present: $py"
    } else {
        $exe = Join-Path $env:TEMP 'python-3.12.10-amd64.exe'
        Download-File -Uri 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-amd64.exe' -OutFile $exe
        Start-Process -FilePath $exe -ArgumentList '/quiet','InstallAllUsers=1','PrependPath=1','Include_pip=1','Include_test=0' -Wait
        $py = "$env:ProgramFiles\Python312\python.exe"
        Write-Ok "Python installed: $py"
    }
    $req = Join-Path $SiteRoot 'collector\requirements.txt'
    if ((Test-Path $py) -and (Test-Path $req)) {
        Write-Host '    pip install -r collector/requirements.txt'
        & $py -m pip install --upgrade pip
        & $py -m pip install -r $req
        Write-Ok 'Python packages installed (pysnmp, cryptography, paramiko, pyodbc)'
    }
}

function Deploy-AppFiles {
    if ($SkipDeploy) { Write-Warn 'Skipping app file copy'; return }
    if (-not $DeploySource) { $script:DeploySource = Split-Path (Split-Path $PSCommandPath -Parent) -Parent }
    if (-not (Test-Path (Join-Path $DeploySource 'public\index.php'))) {
        Write-Warn "DeploySource missing public\index.php: $DeploySource"
        return
    }
    Write-Step "Deploying application files to $SiteRoot"
    New-Item -ItemType Directory -Path $SiteRoot -Force | Out-Null
    $exclude = @('.git','data','logs','storage','php.ini','secrets.env','config\config.php','config\collector.json')
    Get-ChildItem -Path $DeploySource -Force | ForEach-Object {
        if ($exclude -contains $_.Name) { return }
        $dest = Join-Path $SiteRoot $_.Name
        Copy-Item -Path $_.FullName -Destination $dest -Recurse -Force
    }
    foreach ($d in @('data','logs','storage\backups','storage\tmp','config','public\assets\tpl')) {
        New-Item -ItemType Directory -Path (Join-Path $SiteRoot $d) -Force | Out-Null
    }
    New-Item -ItemType Directory -Path 'C:\ProgramData\BackAisle' -Force | Out-Null
    Write-Ok "Files copied (preserved data/logs/secrets/config.php if present)"
}

function Install-BackAisleSite([string]$IniPath) {
    Write-Step "IIS site $SiteName on :$HttpPort (does not change Default Web Site)"
    Import-Module WebAdministration -ErrorAction Stop
    $public = Join-Path $SiteRoot 'public'
    if (-not (Test-Path "IIS:\AppPools\$PoolName")) {
        New-WebAppPool -Name $PoolName | Out-Null
    }
    Set-ItemProperty "IIS:\AppPools\$PoolName" -Name managedRuntimeVersion -Value ''
    Set-ItemProperty "IIS:\AppPools\$PoolName" -Name managedPipelineMode -Value Integrated
    Set-ItemProperty "IIS:\AppPools\$PoolName" -Name processModel.identityType -Value ApplicationPoolIdentity
    $phpCgi = Join-Path $PhpInstallPath 'php-cgi.exe'
    $phpArgs = "-c `"$IniPath`""
    $fcgi = Get-WebConfiguration -Filter 'system.webServer/fastCgi' | Select-Object -ExpandProperty Collection
    $have = $false
    foreach ($app in $fcgi) {
        if ($app.fullPath -eq $phpCgi -and $app.arguments -eq $phpArgs) { $have = $true }
    }
    if (-not $have) {
        Add-WebConfiguration -Filter 'system.webServer/fastCgi' -Value @{
            fullPath = $phpCgi
            arguments = $phpArgs
            instanceMaxRequests = 10000
            activityTimeout = 180
            requestTimeout = 180
        }
        Write-Ok 'Registered site-local FastCGI (-c php.ini)'
    }
    $existing = Get-Website | Where-Object { $_.Name -eq $SiteName }
    if (-not $existing) {
        New-Website -Name $SiteName -Port $HttpPort -PhysicalPath $public -ApplicationPool $PoolName | Out-Null
    } else {
        Set-ItemProperty "IIS:\Sites\$SiteName" -Name physicalPath -Value $public
        Set-ItemProperty "IIS:\Sites\$SiteName" -Name applicationPool -Value $PoolName
    }
    icacls $SiteRoot /grant "${PoolName}:(OI)(CI)RX" /T | Out-Null
    foreach ($p in @('data','logs','storage','config')) {
        $full = Join-Path $SiteRoot $p
        icacls $full /grant "IIS APPPOOL\${PoolName}:(OI)(CI)M" /T | Out-Null
        icacls $full /grant "IUSR:(OI)(CI)M" /T | Out-Null
    }
    icacls 'C:\ProgramData\BackAisle' /grant "IIS APPPOOL\${PoolName}:(OI)(CI)M" /T | Out-Null
    Start-WebAppPool $PoolName
    Start-Website $SiteName
    Write-Ok "Site $SiteName listening on :$HttpPort"
}

# ---- main ----
Assert-Admin
Ensure-Tls12
if (-not $DeploySource) { $DeploySource = Split-Path (Split-Path $PSCommandPath -Parent) -Parent }

Write-Host ''
Write-Host '  BackAisle platform installer' -ForegroundColor White
Write-Host '  https://github.com/sabap/BackAisle' -ForegroundColor DarkGray
Write-Host '  Does not modify Default Web Site or ColdAisle.' -ForegroundColor DarkGray
Write-Host ''

Install-IisFeatures
Install-VcRedist
Install-Php
Deploy-AppFiles
$ini = Write-SitePhpIni
Install-Odbc18
Install-UrlRewrite
Install-Python
Install-BackAisleSite -IniPath $ini

if ($RegisterCollectorTask) {
    $reg = Join-Path $SiteRoot 'scripts\Register-BackAisle-CollectorTask.ps1'
    if (Test-Path $reg) { & $reg -SiteRoot $SiteRoot }
}

$setupUrl = "http://localhost:$HttpPort/setup.php"
Write-Host ''
Write-Host '================================================================' -ForegroundColor Green
Write-Host '  BackAisle platform install finished' -ForegroundColor Green
Write-Host '================================================================' -ForegroundColor Green
Write-Host @"

  Site:     $SiteRoot
  IIS:      $SiteName :$HttpPort  (pool $PoolName)
  PHP:      $PhpInstallPath  (ini $ini)
  Next:     $setupUrl
            - Connect SQLite or SQL Server
            - Restore a site backup, or
            - Import a PowerPanel profile.zip

  SQL Server is NOT installed by this script.
  Default Web Site / port 80 is unchanged.

"@
if ($OpenSetup) {
    try { Start-Process $setupUrl } catch { Write-Warn "Open $setupUrl manually" }
}
