#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Overlay the latest BackAisle application files onto an existing install.
    Does not wipe IIS, SQL, SQLite, secrets, or php.ini.
#>
[CmdletBinding()]
param(
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [string]$Ref = 'main',
    [string]$Owner = 'sabap',
    [string]$Repo = 'BackAisle',
    [string]$PoolName = 'BackAisle'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Step([string]$m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Write-Ok([string]$m) { Write-Host "    [OK] $m" -ForegroundColor Green }
function Write-Warn([string]$m) { Write-Host "    [WARN] $m" -ForegroundColor Yellow }

function Test-BaNotHtml([string]$Path, [int]$MinBytes = 8) {
    if (-not (Test-Path -LiteralPath $Path)) { return $false }
    if ((Get-Item -LiteralPath $Path).Length -lt $MinBytes) { return $false }
    $fs = [IO.File]::OpenRead($Path)
    try {
        $b = New-Object byte[] 16
        $n = $fs.Read($b, 0, 16)
        if ($n -lt 1) { return $false }
        if ($b[0] -eq 0x3C -and $n -ge 2 -and $b[1] -ne 0x3F) { return $false }
        return $true
    } finally { $fs.Close() }
}

function Invoke-BaGet([string]$Uri, [string]$OutFile, [int]$MinBytes = 8) {
    $dir = Split-Path -Parent $OutFile
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    $ok = $false
    if (Get-Command curl.exe -ErrorAction SilentlyContinue) {
        foreach ($useRevoke in @($false, $true)) {
            try {
                if ($useRevoke) {
                    cmd /c "curl.exe -fsSL -L --ssl-no-revoke --retry 2 --max-time 120 -A BackAisle-Updater -o `"$OutFile`" $Uri"
                } else {
                    cmd /c "curl.exe -fsSL -L --retry 2 --max-time 120 -A BackAisle-Updater -o `"$OutFile`" $Uri"
                }
            } catch { }
            if ($LASTEXITCODE -eq 0 -and (Test-BaNotHtml -Path $OutFile -MinBytes $MinBytes)) { $ok = $true; break }
        }
    }
    if (-not $ok) {
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -Uri $Uri -OutFile $OutFile -UseBasicParsing -Headers @{ 'User-Agent' = 'BackAisle-Updater' }
            if (Test-BaNotHtml -Path $OutFile -MinBytes $MinBytes) { $ok = $true }
        } catch { }
    }
    if (-not $ok) { throw "Download failed or was HTML (proxy): $Uri. Copy the BackAisle folder onto this server instead." }
}

function Get-JsDelivrRelPaths($node, [string]$prefix) {
    $acc = New-Object System.Collections.Generic.List[string]
    foreach ($f in @($node.files)) {
        $p = if ($prefix) { "$prefix/$($f.name)" } else { [string]$f.name }
        if ($p -match '^\.(git|grok)(/|$)') { continue }
        if ([string]$f.type -eq 'file') { [void]$acc.Add($p) }
        elseif ($f.files) {
            foreach ($c in Get-JsDelivrRelPaths $f $p) { [void]$acc.Add($c) }
        }
    }
    return $acc
}

function Test-PreserveRel([string]$rel) {
    $n = ($rel -replace '\\', '/').TrimStart('/')
    foreach ($p in @(
        'data/', 'logs/', 'storage/backups/', 'storage/tmp/',
        'php.ini', 'config/config.php', 'config/collector.json',
        'public/assets/tpl/', 'public/web.config'
    )) {
        if ($n -eq $p.TrimEnd('/') -or ($p.EndsWith('/') -and $n.StartsWith($p))) { return $true }
    }
    if ($n -eq 'secrets.env') { return $true }
    if ($n -match '\.db(-wal|-shm)?$') { return $true }
    return $false
}

if (-not (Test-Path (Join-Path $SiteRoot 'public\index.php'))) {
    throw "This does not look like a BackAisle install: missing $SiteRoot\public\index.php. Run the full installer first, do not use this updater."
}

Write-Host ''
Write-Host '  BackAisle production overlay (keeps database, config, secrets, php.ini)' -ForegroundColor White
Write-Host ''

$work = Join-Path $env:TEMP ("BackAisle-update-{0}" -f [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $work | Out-Null
$got = $false
foreach ($tryRef in @($Ref, 'main')) {
    try {
        Write-Step "File list from jsDelivr @$tryRef"
        $api = "https://data.jsdelivr.com/v1/packages/gh/$Owner/$Repo@$tryRef"
        $metaFile = Join-Path $work 'meta.json'
        Invoke-BaGet -Uri $api -OutFile $metaFile -MinBytes 20
        $meta = Get-Content -LiteralPath $metaFile -Raw -Encoding UTF8 | ConvertFrom-Json
        $files = @(Get-JsDelivrRelPaths $meta '')
        if ($files.Count -lt 10) { throw "file list too small ($($files.Count))" }
        $src = Join-Path $work 'src'
        New-Item -ItemType Directory -Force -Path $src | Out-Null
        $n = 0
        foreach ($rel in $files) {
            if (Test-PreserveRel $rel) { continue }
            $url = "https://cdn.jsdelivr.net/gh/$Owner/$Repo@$tryRef/$rel"
            $out = Join-Path $src ($rel -replace '/', '\')
            Invoke-BaGet -Uri $url -OutFile $out -MinBytes 1
            $n++
        }
        if (-not (Test-Path (Join-Path $src 'public\index.php'))) { throw 'jsDelivr tree missing public/index.php' }
        Write-Ok "Fetched $n files @$tryRef"
        Write-Step "Overlay onto $SiteRoot (preserving data, config.php, collector.json, php.ini, secrets, logs)"
        Get-ChildItem -Path $src -Recurse -File -Force | ForEach-Object {
            $rel = $_.FullName.Substring($src.Length).TrimStart('\', '/')
            $relFwd = $rel -replace '\\', '/'
            if (Test-PreserveRel $relFwd) { return }
            $target = Join-Path $SiteRoot $rel
            $td = Split-Path -Parent $target
            if ($td -and -not (Test-Path $td)) {
                New-Item -ItemType Directory -Force -Path $td | Out-Null
            }
            Copy-Item -LiteralPath $_.FullName -Destination $target -Force
        }
        $got = $true
        break
    } catch {
        Write-Warn $_.Exception.Message
    }
}
if (-not $got) {
    throw 'Could not overlay from jsDelivr. Copy the BackAisle application folder onto this server (keep data, config, php.ini, secrets.env).'
}

$verFile = Join-Path $SiteRoot 'VERSION'
if (Test-Path $verFile) {
    $vl = @(Get-Content $verFile -TotalCount 1 -ErrorAction SilentlyContinue)
    if ($vl.Count -gt 0 -and $vl[0]) { Write-Ok ("Now at VERSION " + $vl[0].ToString().Trim()) }
}

try {
    Import-Module WebAdministration
    Restart-WebAppPool -Name $PoolName
    Write-Ok "Restarted app pool $PoolName"
} catch {
    throw "Files copied but IIS pool '$PoolName' did not restart: $($_.Exception.Message). Recycle the BackAisle pool in IIS Manager."
}
Write-Host ''
Write-Host '  Production overlay finished. Open http://localhost/admin.php and http://localhost/home.php' -ForegroundColor Green
Write-Host ''
