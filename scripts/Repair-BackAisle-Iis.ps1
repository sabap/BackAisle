#Requires -RunAsAdministrator
# Fix IIS/PHP 500 for BackAisle on :8080. ASCII. Safe to re-run.
[CmdletBinding()]
param(
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [string]$PhpInstallPath = 'C:\PHP',
    [string]$SiteName = 'BackAisle',
    [string]$PoolName = 'BackAisle'
)

$ErrorActionPreference = 'Continue'
Import-Module WebAdministration

$phpCgi = Join-Path $PhpInstallPath 'php-cgi.exe'
$phpExe = Join-Path $PhpInstallPath 'php.exe'
$ini = Join-Path $SiteRoot 'php.ini'
$sess = Join-Path $SiteRoot 'data\sessions'
$logDir = Join-Path $SiteRoot 'logs'
$phpArgs = "-c $ini"

Write-Host '==> BackAisle IIS repair'

if (-not (Test-Path $phpCgi)) { throw "Missing $phpCgi" }
if (-not (Test-Path $ini)) { throw "Missing $ini" }

New-Item -ItemType Directory -Path $sess, $logDir -Force | Out-Null
$health = Join-Path $SiteRoot 'public\health.php'
if (-not (Test-Path $health)) {
    Set-Content -Path $health -Encoding ASCII -Value @'
<?php
header("Content-Type: text/plain");
echo "ok\nphp=".PHP_VERSION."\nini=".(string)php_ini_loaded_file()."\n";
'@
}

$rw = Join-Path $env:SystemRoot 'System32\inetsrv\rewrite.dll'
Write-Host "    URL Rewrite: $(Test-Path $rw)"
if (-not (Test-Path $rw)) {
    Write-Host '    Installing URL Rewrite...'
    $msi = Join-Path $env:TEMP 'rewrite_amd64_en-US.msi'
    & curl.exe -fL --ssl-no-revoke --retry 2 --max-time 120 -o $msi 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi'
    if ($LASTEXITCODE -eq 0 -and (Test-Path $msi)) {
        Start-Process msiexec.exe -ArgumentList "/i `"$msi`" /qn /norestart" -Wait
    }
}

$marker = '; BackAisle site overrides'
$cur = Get-Content -Path $ini -Raw
if ($cur -notmatch [regex]::Escape($marker)) {
    Add-Content -Path $ini -Value @"

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

"@ -Encoding ASCII
    Write-Host '    Appended php.ini overrides'
}

$old = $ErrorActionPreference
$ErrorActionPreference = 'SilentlyContinue'
foreach ($id in @("IIS APPPOOL\$PoolName", 'NT AUTHORITY\IUSR', 'IIS_IUSRS')) {
    & icacls.exe $SiteRoot /grant "${id}:(OI)(CI)M" /T /C /Q | Out-Null
}
$ErrorActionPreference = $old

$have = $false
$fcgi = Get-WebConfiguration -Filter 'system.webServer/fastCgi' | Select-Object -ExpandProperty Collection
foreach ($app in $fcgi) {
    if ($app.fullPath -eq $phpCgi -and ($app.arguments -eq $phpArgs -or $app.arguments -eq "-c `"$ini`"")) { $have = $true }
}
if (-not $have) {
    Add-WebConfiguration -Filter 'system.webServer/fastCgi' -Value @{
        fullPath = $phpCgi
        arguments = $phpArgs
        instanceMaxRequests = 10000
        activityTimeout = 180
        requestTimeout = 180
    }
    Write-Host "    Registered FastCGI $phpCgi $phpArgs"
}

try { Restart-WebAppPool $PoolName } catch { Start-WebAppPool $PoolName }
Start-Website $SiteName -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

Write-Host '==> PHP CLI'
& $phpExe -c $ini -v
& $phpExe -c $ini -m | Select-String 'pdo|sqlite|curl|ldap|zip|mbstring|openssl'
& $phpExe -c $ini -l (Join-Path $SiteRoot 'public\setup.php')
Write-Host '==> setup.php CLI (first 30 lines)'
& $phpExe -c $ini -d display_errors=1 (Join-Path $SiteRoot 'public\setup.php') 2>&1 | Select-Object -First 30

Write-Host '==> HTTP'
& curl.exe -sS -D - --max-time 15 "http://127.0.0.1:8080/health.php" -o -
Write-Host ''
& curl.exe -sS -D - --max-time 15 "http://127.0.0.1:8080/setup.php" -o - | Select-Object -First 25

Write-Host '==> php-error.log (tail)'
$log = Join-Path $logDir 'php-error.log'
if (Test-Path $log) { Get-Content $log -Tail 40 } else { Write-Host '    (no log yet)' }

Write-Host '==> Done. Open http://localhost:8080/setup.php'
