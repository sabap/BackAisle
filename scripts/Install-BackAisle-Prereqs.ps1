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
    PHP NTS build on windows.php.net. Default 8.3.33 (falls back to the 8.3 latest zip / archives)

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
    [string]$PhpVersion = '8.3.33',
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
    try { [Net.ServicePointManager]::CheckCertificateRevocationList = $false } catch { }
}

function Test-CommandExists([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Test-DownloadedFile {
    param([string]$Path, [int]$MinBytes = 1024, [switch]$RequireZip)
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

function Invoke-CurlGet {
    param([string]$Uri, [string]$OutFile, [int]$TimeoutSec = 180)
    if (-not (Test-CommandExists 'curl.exe')) {
        return @{ Ok = $false; ExitCode = -1; Detail = 'curl.exe not found' }
    }
    $sets = @(
        @('-fL', '--retry', '2', '--max-time', "$TimeoutSec", '-A', 'BackAisle-Installer', '-o', $OutFile, $Uri),
        @('-fL', '--ssl-no-revoke', '--retry', '2', '--max-time', "$TimeoutSec", '-A', 'BackAisle-Installer', '-o', $OutFile, $Uri)
    )
    $code = -1
    $detail = ''
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        foreach ($a in $sets) {
            if (Test-Path -LiteralPath $OutFile) { Remove-Item -LiteralPath $OutFile -Force -ErrorAction SilentlyContinue }
            $detail = ((& curl.exe @a 2>&1) | Out-String).Trim()
            $code = $LASTEXITCODE
            if ($code -eq 0 -and (Test-Path -LiteralPath $OutFile) -and ((Get-Item -LiteralPath $OutFile).Length -gt 0)) {
                return @{ Ok = $true; ExitCode = 0; Detail = '' }
            }
        }
    } finally { $ErrorActionPreference = $old }
    return @{ Ok = $false; ExitCode = $code; Detail = $detail }
}

function Download-File {
    param(
        [string]$Uri,
        [string]$OutFile,
        [int]$MinBytes = 1024,
        [switch]$RequireZip
    )
    Ensure-Tls12
    try { [Net.ServicePointManager]::CheckCertificateRevocationList = $false } catch { }
    Write-Host "    Downloading: $Uri"
    if ((Test-DownloadedFile -Path $OutFile -MinBytes $MinBytes -RequireZip:$RequireZip) -and -not $Force) {
        Write-Ok "Already present: $OutFile"
        return
    }
    $dir = Split-Path -Parent $OutFile
    if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $ok = $false
    $why = ''
    $curl = Invoke-CurlGet -Uri $Uri -OutFile $OutFile
    if ($curl.Ok -and (Test-DownloadedFile -Path $OutFile -MinBytes $MinBytes -RequireZip:$RequireZip)) {
        $ok = $true
    } else {
        if (-not $curl.Ok) { $why = "curl exit $($curl.ExitCode) $($curl.Detail)" }
        elseif (Test-Path -LiteralPath $OutFile) {
            $len = (Get-Item -LiteralPath $OutFile).Length
            $why = "file was $len bytes (need zip=$RequireZip min=$MinBytes)"
        }
    }
    if (-not $ok) {
        try {
            if (Test-Path -LiteralPath $OutFile) { Remove-Item -LiteralPath $OutFile -Force -ErrorAction SilentlyContinue }
            Invoke-WebRequest -Uri $Uri -OutFile $OutFile -UseBasicParsing
            if (Test-DownloadedFile -Path $OutFile -MinBytes $MinBytes -RequireZip:$RequireZip) { $ok = $true }
        } catch {
            if (-not $why) { $why = $_.Exception.Message }
        }
    }
    if (-not $ok) { throw "Download failed: $Uri  $why" }
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

function Get-PhpZipUrls([string]$Version) {
    $v = $Version.Trim()
    if ($v -notmatch '^(\d+)\.(\d+)') { $v = '8.3.33'; $majmin = '8.3' }
    else { $majmin = "$($Matches[1]).$($Matches[2])" }
    $vs = 'vs16'
    try { if ([version]$majmin -ge [version]'8.4') { $vs = 'vs17' } } catch { }

    $urls = New-Object System.Collections.Generic.List[string]
    function Add-Url([string]$u) { if ($u -and -not $urls.Contains($u)) { [void]$urls.Add($u) } }

    # Unversioned latest alias does not 404 when a patch leaves /releases/.
    Add-Url "https://downloads.php.net/~windows/releases/latest/php-$majmin-nts-Win32-$vs-x64-latest.zip"
    Add-Url "https://windows.php.net/downloads/releases/latest/php-$majmin-nts-Win32-$vs-x64-latest.zip"

    if ($v -match '^\d+\.\d+\.\d+') {
        $fn = "php-$v-nts-Win32-$vs-x64.zip"
        Add-Url "https://downloads.php.net/~windows/releases/archives/$fn"
        Add-Url "https://windows.php.net/downloads/releases/archives/$fn"
        Add-Url "https://downloads.php.net/~windows/releases/$fn"
        Add-Url "https://windows.php.net/downloads/releases/$fn"
    }

    try {
        $rj = Join-Path $env:TEMP 'php-releases.json'
        $r = Invoke-CurlGet -Uri 'https://downloads.php.net/~windows/releases/releases.json' -OutFile $rj -TimeoutSec 30
        if ($r.Ok) {
            $rel = Get-Content -LiteralPath $rj -Raw -Encoding UTF8 | ConvertFrom-Json
            $series = $rel.PSObject.Properties[$majmin].Value
            if ($series) {
                $nts = $series.PSObject.Properties["nts-$vs-x64"].Value
                $path = [string]$nts.zip.path
                if ($path) {
                    Add-Url "https://downloads.php.net/~windows/releases/$path"
                    Add-Url "https://windows.php.net/downloads/releases/$path"
                    Add-Url "https://downloads.php.net/~windows/releases/archives/$path"
                }
            }
        }
    } catch {
        Write-Warn "releases.json skipped: $($_.Exception.Message)"
    }
    return $urls
}

function Install-Php {
    Write-Step "PHP $PhpVersion NTS x64 (shared binaries at $PhpInstallPath)"
    $phpCgi = Join-Path $PhpInstallPath 'php-cgi.exe'
    if ((Test-Path $phpCgi) -and -not $Force) {
        Write-Ok "PHP already present at $PhpInstallPath (will not overwrite another site's php.ini)"
    } else {
        $zip = Join-Path $env:TEMP "php-$PhpVersion-nts-x64.zip"
        $urls = @(Get-PhpZipUrls -Version $PhpVersion)
        $got = $false
        foreach ($url in $urls) {
            try {
                Download-File -Uri $url -OutFile $zip -MinBytes 5000000 -RequireZip
                $got = $true
                break
            } catch {
                Write-Warn $_.Exception.Message
            }
        }
        if (-not $got) {
            throw "Could not download PHP $PhpVersion NTS x64. Install PHP to $PhpInstallPath (php-cgi.exe) or pass -PhpVersion from https://windows.php.net/download/"
        }
        New-Item -ItemType Directory -Path $PhpInstallPath -Force | Out-Null
        Expand-Archive -LiteralPath $zip -DestinationPath $PhpInstallPath -Force
        Write-Ok "PHP extracted to $PhpInstallPath"
    }
    if (-not (Test-Path $phpCgi)) { throw "php-cgi.exe missing under $PhpInstallPath" }
}

function Write-SitePhpIni {
    Write-Step 'Site-local php.ini for BackAisle (does not change global php.ini)'
    $prod = Join-Path $PhpInstallPath 'php.ini-production'
    $ini = Join-Path $SiteRoot 'php.ini'
    $sess = Join-Path $SiteRoot 'data\sessions'
    $logDir = Join-Path $SiteRoot 'logs'
    New-Item -ItemType Directory -Path $sess, $logDir -Force | Out-Null
    if (-not (Test-Path $prod)) { throw "php.ini-production missing in $PhpInstallPath" }
    if (-not (Test-Path $ini) -or $Force) {
        $c = Get-Content -Path $prod -Raw
        $c = Set-IniValue $c 'extension_dir' "`"$PhpInstallPath\ext`""
        $c = Set-IniValue $c 'cgi.fix_path_info' '1'
        $c = Set-IniValue $c 'fastcgi.impersonate' '1'
        $c = Set-IniValue $c 'date.timezone' 'UTC'
        $c = Set-IniValue $c 'expose_php' 'Off'
        $c = Set-IniValue $c 'display_errors' 'On'
        $c = Set-IniValue $c 'log_errors' 'On'
        $c = Set-IniValue $c 'error_log' "`"$logDir\php-error.log`""
        $c = Set-IniValue $c 'upload_max_filesize' '64M'
        $c = Set-IniValue $c 'post_max_size' '64M'
        $c = Set-IniValue $c 'memory_limit' '256M'
        $c = Set-IniValue $c 'max_execution_time' '180'
        $c = Set-IniValue $c 'session.save_path' "`"$sess`""
        $c = Set-IniValue $c 'sys_temp_dir' "`"$sess`""
        foreach ($ext in @('curl','mbstring','openssl','fileinfo','ldap','pdo_sqlite','sqlite3','pdo_odbc','zip')) {
            $c = Set-IniValue $c $ext -IsExtension
        }
        foreach ($ext in @('pdo_sqlsrv','sqlsrv')) {
            $dll = Join-Path $PhpInstallPath "ext\php_$ext.dll"
            if (Test-Path $dll) { $c = Set-IniValue $c $ext -IsExtension }
        }
        Set-Content -Path $ini -Value $c -Encoding ASCII
        Write-Ok "Wrote $ini"
    } else {
        Write-Ok "Keeping existing $ini"
    }
    $marker = '; BackAisle site overrides'
    $cur = Get-Content -Path $ini -Raw
    if ($cur -notmatch [regex]::Escape($marker)) {
        $block = @"

$marker (last value wins)
cgi.fix_path_info = 1
fastcgi.impersonate = 1
display_errors = On
display_startup_errors = On
log_errors = On
error_log = "$logDir\php-error.log"
session.save_path = "$sess"
sys_temp_dir = "$sess"
extension_dir = "$PhpInstallPath\ext"
extension=curl
extension=mbstring
extension=openssl
extension=fileinfo
extension=ldap
extension=pdo_sqlite
extension=sqlite3
extension=pdo_odbc
extension=zip

"@
        Add-Content -Path $ini -Value $block -Encoding ASCII
        Write-Ok "Appended site overrides to $ini"
    }
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

function Test-RealPythonExe([string]$Path) {
    if (-not $Path) { return $false }
    if (-not (Test-Path -LiteralPath $Path)) { return $false }
    $full = [IO.Path]::GetFullPath($Path)
    if ($full -match '(?i)\\WindowsApps\\') { return $false }
    $item = Get-Item -LiteralPath $full -ErrorAction SilentlyContinue
    if (-not $item -or $item.Length -lt 2048) { return $false }
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $full -c "import sys; print('%d.%d' % sys.version_info[:2])" 2>&1
        if ($LASTEXITCODE -ne 0) { return $false }
        $ver = ([string]$out).Trim()
        if ($ver -notmatch '^(\d+)\.(\d+)$') { return $false }
        $maj = [int]$Matches[1]; $min = [int]$Matches[2]
        return ($maj -gt 3) -or ($maj -eq 3 -and $min -ge 12)
    } catch {
        return $false
    } finally {
        $ErrorActionPreference = $old
    }
}

function Find-RealPython {
    $candidates = New-Object System.Collections.Generic.List[string]
    foreach ($c in @(
        "$env:ProgramFiles\Python312\python.exe",
        "$env:ProgramFiles\Python313\python.exe",
        "$env:LocalAppData\Programs\Python\Python312\python.exe",
        "$env:LocalAppData\Programs\Python\Python313\python.exe"
    )) {
        if (Test-Path -LiteralPath $c) { [void]$candidates.Add($c) }
    }
    foreach ($root in @("$env:ProgramFiles", "$env:LocalAppData\Programs\Python")) {
        if (Test-Path $root) {
            Get-ChildItem -Path $root -Directory -ErrorAction SilentlyContinue |
                Where-Object { $_.Name -match '^Python3\d' } |
                ForEach-Object {
                    $p = Join-Path $_.FullName 'python.exe'
                    if (Test-Path -LiteralPath $p) { [void]$candidates.Add($p) }
                }
        }
    }
    $pyLauncher = Get-Command py.exe -ErrorAction SilentlyContinue
    if ($pyLauncher -and $pyLauncher.Source -notmatch '(?i)\\WindowsApps\\') {
        $old = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            foreach ($tag in @('-3.12', '-3.13', '-3')) {
                $loc = & py.exe $tag -c "import sys; print(sys.executable)" 2>$null
                if ($LASTEXITCODE -eq 0 -and $loc) {
                    $t = ([string]$loc).Trim()
                    if ($t) { $candidates.Insert(0, $t) }
                }
            }
        } catch { }
        finally { $ErrorActionPreference = $old }
    }
    $cmd = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source -notmatch '(?i)\\WindowsApps\\') { [void]$candidates.Add($cmd.Source) }
    foreach ($c in $candidates) {
        if (Test-RealPythonExe $c) { return $c }
    }
    return $null
}

function Install-Python {
    if ($SkipPython) { Write-Warn 'Skipping Python'; return }
    Write-Step 'Python 3.12+'
    $py = Find-RealPython
    if ($py -and -not $Force) {
        Write-Ok "Python present: $py"
    } else {
        $stub = Get-Command python.exe -ErrorAction SilentlyContinue
        if ($stub -and $stub.Source -match '(?i)\\WindowsApps\\') {
            Write-Warn "Ignoring Microsoft Store python stub: $($stub.Source)"
        }
        $exe = Join-Path $env:TEMP 'python-3.12.10-amd64.exe'
        Download-File -Uri 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-amd64.exe' -OutFile $exe
        Write-Host '    Installing Python 3.12.10 for all users...'
        $p = Start-Process -FilePath $exe -ArgumentList '/quiet','InstallAllUsers=1','PrependPath=1','Include_pip=1','Include_test=0','SimpleInstall=1' -Wait -PassThru
        if ($p.ExitCode -notin 0, 3010) {
            Write-Warn "Python installer exit $($p.ExitCode)"
        }
        $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
        $py = Find-RealPython
        if (-not $py -and (Test-Path "$env:ProgramFiles\Python312\python.exe")) {
            $py = "$env:ProgramFiles\Python312\python.exe"
        }
        if (-not $py) { throw 'Python 3.12 install finished but python.exe was not found. Install from https://www.python.org/downloads/windows/ (disable App execution aliases for python.exe) and re-run.' }
        Write-Ok "Python installed: $py"
    }
    $req = Join-Path $SiteRoot 'collector\requirements.txt'
    if (-not (Test-Path -LiteralPath $req)) {
        Write-Warn "No collector/requirements.txt at $req"
        return
    }
    Write-Host "    $py -m pip install -r collector/requirements.txt"
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $py -m pip install --upgrade pip
        & $py -m pip install -r $req
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $old
    }
    if ($code -ne 0) { throw "pip install failed (exit $code) using $py" }
    Write-Ok "Python packages installed (pysnmp, cryptography, paramiko, pyodbc) via $py"
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

function Grant-BaAcl {
    param([string]$Path, [string]$Identity, [string]$Rights)
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $null = & icacls.exe $Path /grant "${Identity}:(OI)(CI)${Rights}" /T /C /Q 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Warn "icacls $Identity on $Path exit $LASTEXITCODE"
        }
    } catch {
        Write-Warn "icacls $Identity on $Path : $($_.Exception.Message)"
    } finally {
        $ErrorActionPreference = $old
    }
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
    $phpArgs = "-c $IniPath"
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
    # Virtual account exists after the pool is created/started. Never grant the bare
    # pool name (icacls "BackAisle:..." cannot map a SID).
    try { Start-WebAppPool $PoolName } catch { }
    $poolId = "IIS APPPOOL\$PoolName"
    $identities = @($poolId, 'NT AUTHORITY\IUSR', 'IIS_IUSRS')
    foreach ($id in $identities) {
        Grant-BaAcl -Path $SiteRoot -Identity $id -Rights 'M'
        Grant-BaAcl -Path 'C:\ProgramData\BackAisle' -Identity $id -Rights 'M'
    }
    foreach ($p in @('data','logs','storage','config')) {
        $full = Join-Path $SiteRoot $p
        if (-not (Test-Path $full)) { New-Item -ItemType Directory -Path $full -Force | Out-Null }
        foreach ($id in $identities) { Grant-BaAcl -Path $full -Identity $id -Rights 'M' }
    }
    Write-Ok "NTFS Modify granted to $($identities -join ', ')"
    try { Start-Website $SiteName } catch { Write-Warn "Start-Website: $($_.Exception.Message)" }
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
    if (Test-Path $reg) {
        try { & $reg -SiteRoot $SiteRoot }
        catch { Write-Warn "Collector task registration: $($_.Exception.Message)" }
    }
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
