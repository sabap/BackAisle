# Creates ONLY the BackAisle IIS site and app pool. Does not modify Default Web Site / ColdAisle.
$ErrorActionPreference = 'Stop'
Import-Module WebAdministration

$siteName = 'BackAisle'
$poolName = 'BackAisle'
$root = 'C:\inetpub\BackAisle\public'
$php = 'C:\PHP\php-cgi.exe'
$phpIni = 'C:\inetpub\BackAisle\php.ini'
$phpArgs = "-c $phpIni"

if (-not (Test-Path $root)) { throw "Missing $root" }

if (-not (Test-Path "IIS:\AppPools\$poolName")) {
    New-WebAppPool -Name $poolName | Out-Null
}
Set-ItemProperty "IIS:\AppPools\$poolName" -Name managedRuntimeVersion -Value ''
Set-ItemProperty "IIS:\AppPools\$poolName" -Name managedPipelineMode -Value Integrated
Set-ItemProperty "IIS:\AppPools\$poolName" -Name processModel.identityType -Value ApplicationPoolIdentity
Set-ItemProperty "IIS:\AppPools\$poolName" -Name startMode -Value AlwaysRunning

$existing = Get-Website | Where-Object { $_.Name -eq $siteName }
if (-not $existing) {
    New-Website -Name $siteName -Port 8080 -PhysicalPath $root -ApplicationPool $poolName | Out-Null
} else {
    Set-ItemProperty "IIS:\Sites\$siteName" -Name physicalPath -Value $root
    Set-ItemProperty "IIS:\Sites\$siteName" -Name applicationPool -Value $poolName
}

# Register FastCGI with BackAisle php.ini (does not replace the ColdAisle PHP registration)
$fcgi = Get-WebConfiguration -Filter 'system.webServer/fastCgi' | Select-Object -ExpandProperty Collection
$have = $false
foreach ($app in $fcgi) {
    if ($app.fullPath -eq $php -and $app.arguments -eq $phpArgs) { $have = $true }
}
if (-not $have) {
    Add-WebConfiguration -Filter 'system.webServer/fastCgi' -Value @{
        fullPath = $php
        arguments = $phpArgs
        instanceMaxRequests = 10000
        activityTimeout = 90
        requestTimeout = 90
    }
}

icacls C:\inetpub\BackAisle /grant "${poolName}:(OI)(CI)RX" /T | Out-Null
icacls C:\inetpub\BackAisle\data /grant "IIS APPPOOL\${poolName}:(OI)(CI)M" | Out-Null
icacls C:\inetpub\BackAisle\data /grant "IUSR:(OI)(CI)M" | Out-Null
icacls C:\inetpub\BackAisle\data /grant "IIS_IUSRS:(OI)(CI)M" | Out-Null
icacls C:\inetpub\BackAisle\logs /grant "IIS APPPOOL\${poolName}:(OI)(CI)M" | Out-Null
icacls C:\inetpub\BackAisle\logs /grant "IUSR:(OI)(CI)M" | Out-Null
icacls C:\ProgramData\BackAisle /grant "IIS APPPOOL\${poolName}:(OI)(CI)RX" | Out-Null

Start-WebAppPool $poolName
Start-Website $siteName

Write-Host 'BackAisle IIS site ready on :8080'
Get-Website | Format-Table Name, State, PhysicalPath, applicationPool -AutoSize
