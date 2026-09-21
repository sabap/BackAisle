#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Replace locked collector writer files from jsDelivr and recycle BackAisleWriter.
#>
[CmdletBinding()]
param(
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [string]$Version = '0.5.34',
    [string]$Owner = 'sabap',
    [string]$Repo = 'BackAisle'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-Location -LiteralPath $env:TEMP
$Version = $Version.Trim().TrimStart('v', 'V')

function Test-BaNotHtml([string]$Path, [int]$MinBytes = 200) {
    if (-not (Test-Path -LiteralPath $Path)) { return $false }
    if ((Get-Item -LiteralPath $Path).Length -lt $MinBytes) { return $false }
    $fs = [IO.File]::OpenRead($Path)
    try {
        $b = New-Object byte[] 16
        $n = $fs.Read($b, 0, 16)
        if ($n -lt 1) { return $false }
        if ($b[0] -eq 0x3C) { return $false }
        return $true
    } finally { $fs.Close() }
}

function Invoke-BaGet([string]$Uri, [string]$OutFile, [int]$MinBytes = 200) {
    $dir = Split-Path -Parent $OutFile
    if ($dir -and -not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    $ok = $false
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (Test-Path -LiteralPath $curl) {
        foreach ($noRevoke in @($true, $false)) {
            try {
                if ($noRevoke) {
                    cmd /c "`"$curl`" -fsSL -L --ssl-no-revoke --retry 2 --max-time 120 -A BackAisle-WriterHotfix -o `"$OutFile`" $Uri"
                } else {
                    cmd /c "`"$curl`" -fsSL -L --retry 2 --max-time 120 -A BackAisle-WriterHotfix -o `"$OutFile`" $Uri"
                }
            } catch { }
            if ($LASTEXITCODE -eq 0 -and (Test-BaNotHtml -Path $OutFile -MinBytes $MinBytes)) { $ok = $true; break }
        }
    }
    if (-not $ok) {
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -Uri $Uri -OutFile $OutFile -UseBasicParsing -Headers @{ 'User-Agent' = 'BackAisle-WriterHotfix' }
            if (Test-BaNotHtml -Path $OutFile -MinBytes $MinBytes) { $ok = $true }
        } catch { }
    }
    if (-not $ok) {
        throw "Download failed or was HTML (OpenDNS/proxy): $Uri. Copy collector\writer.py, rmcard_http.py, rmcard_scp.py, config_file.py, and watchdog.ps1 onto $SiteRoot\collector instead, then re-run this script."
    }
}

$destDir = Join-Path $SiteRoot 'collector'
if (-not (Test-Path -LiteralPath $destDir)) { throw "Missing $destDir" }
$tmp = Join-Path $env:TEMP ("ba-writer-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
$stopped = $false

$files = @(
    @{ Name = 'writer.py'; Marker = 'def writer_version' },
    @{ Name = 'rmcard_http.py'; Marker = '_busy_session' },
    @{ Name = 'rmcard_scp.py'; Marker = 'KexAlgorithms' },
    @{ Name = 'config_file.py'; Marker = 'rmcard_snmpv3_auth_code' },
    @{ Name = 'watchdog.ps1'; Marker = 'restart_writer.flag' }
)

try {
    foreach ($f in $files) {
        $js = "https://cdn.jsdelivr.net/gh/$Owner/${Repo}@v$Version/collector/$($f.Name)"
        $out = Join-Path $tmp $f.Name
        Write-Host "GET $js"
        Invoke-BaGet -Uri $js -OutFile $out -MinBytes 200
        $text = [IO.File]::ReadAllText($out)
        if ($text -notmatch [regex]::Escape($f.Marker)) {
            throw "$($f.Name) missing marker $($f.Marker) (wrong file or HTML). Copy that file from a machine that can reach jsDelivr onto $destDir and re-run."
        }
    }

    Write-Host 'Stopping BackAisleWriter'
    Stop-ScheduledTask -TaskName 'BackAisleWriter' -ErrorAction SilentlyContinue
    $stopped = $true
    $pidFile = Join-Path $SiteRoot 'logs\writer.pid'
    Get-CimInstance Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and ($_.CommandLine -like '*writer.py*') } |
        ForEach-Object { Stop-Process -Id ([int]$_.ProcessId) -Force -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $pidFile) {
        $p = (Get-Content -LiteralPath $pidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
        if ($p) {
            $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$p" -ErrorAction SilentlyContinue
            if ($proc -and $proc.Name -match '^pythonw?\.exe$' -and $proc.CommandLine -like '*writer.py*') {
                Stop-Process -Id ([int]$p) -Force -ErrorAction SilentlyContinue
            }
        }
        Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds 2

    foreach ($f in $files) {
        Copy-Item -LiteralPath (Join-Path $tmp $f.Name) -Destination (Join-Path $destDir $f.Name) -Force
        Write-Host "copied $($f.Name)"
    }

    $flagDir = Join-Path $SiteRoot 'storage\tmp'
    if (-not (Test-Path -LiteralPath $flagDir)) {
        New-Item -ItemType Directory -Force -Path $flagDir | Out-Null
    }
    Remove-Item -LiteralPath (Join-Path $flagDir 'restart_writer.flag') -Force -ErrorAction SilentlyContinue

    Start-ScheduledTask -TaskName 'BackAisleWriter'
    $stopped = $false
    Write-Host "Started BackAisleWriter. Confirm logs\writer.log contains: writer starting version=$Version"
}
finally {
    if ($stopped) {
        try { Start-ScheduledTask -TaskName 'BackAisleWriter' } catch { }
    }
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
