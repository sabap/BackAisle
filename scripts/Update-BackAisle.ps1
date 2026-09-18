#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Overlay the latest BackAisle application files onto an existing install.
    Does not wipe IIS, SQL, SQLite, secrets, or php.ini.
#>
[CmdletBinding()]
param(
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [string]$Ref = 'latest',
    [string]$MinVersion = '0.5.9',
    [string]$Owner = 'sabap',
    [string]$Repo = 'BackAisle',
    [string]$PoolName = 'BackAisle'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Step([string]$m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Write-Ok([string]$m) { Write-Host "    [OK] $m" -ForegroundColor Green }
function Write-Warn([string]$m) { Write-Host "    [WARN] $m" -ForegroundColor Yellow }

function Get-BaSemver([string]$s) {
    if (-not $s) { return $null }
    $t = ($s.Trim() -replace '^[vV]', '')
    if ($t -match '^(\d+\.\d+(?:\.\d+)?)') { return $Matches[1] }
    return $null
}

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

function Get-JsDelivrVersionTag([string]$tag) {
    $safe = ($tag -replace '[^\w\.]', '_')
    $vf = Join-Path $work ("ver-$safe.txt")
    try {
        Invoke-BaGet -Uri "https://cdn.jsdelivr.net/gh/$Owner/$Repo@$tag/VERSION" -OutFile $vf -MinBytes 1
        $line = ''
        $vl = @(Get-Content -LiteralPath $vf -TotalCount 1 -ErrorAction SilentlyContinue)
        if ($vl.Count -gt 0 -and $vl[0]) { $line = [string]$vl[0] }
        return Get-BaSemver $line
    } catch {
        return $null
    }
}

$verHash = @{}
function Add-BaVer([string]$s) {
    $v = Get-BaSemver $s
    if ($v) { $verHash[$v] = $true }
}

Add-BaVer '0.5.9'
Add-BaVer '0.5.8'
Add-BaVer '0.5.7'
Add-BaVer '0.5.6'
Add-BaVer '0.5.4'
Add-BaVer '0.5.3'
Add-BaVer '0.5.2'
Add-BaVer '0.5.1'
Add-BaVer '0.5.0'
Add-BaVer '0.4.9'
if ($Ref -and $Ref -ne 'latest' -and $Ref -ne 'main') {
    Add-BaVer $Ref
}

try {
    Write-Step 'Resolving latest version on jsDelivr (not cached @main)'
    $pkgFile = Join-Path $work 'pkg.json'
    Invoke-BaGet -Uri "https://data.jsdelivr.com/v1/packages/gh/$Owner/$Repo" -OutFile $pkgFile -MinBytes 20
    $pkg = Get-Content -LiteralPath $pkgFile -Raw -Encoding UTF8 | ConvertFrom-Json
    foreach ($row in @($pkg.versions)) {
        Add-BaVer ([string]$row.version)
    }
    $best = $null
    foreach ($v in @($verHash.Keys)) {
        if ($null -eq $best) { $best = $v }
        else {
            try {
                if ([version]$v -gt [version]$best) { $best = $v }
            } catch { }
        }
    }
    if ($best) { Write-Ok "jsDelivr catalog / seed latest: $best" }
} catch {
    Write-Warn $_.Exception.Message
}

$floor = Get-BaSemver $MinVersion
if (-not $floor) { $floor = '0.5.9' }
foreach ($v in @($verHash.Keys)) {
    try {
        if ([version]$v -gt [version]$floor) { $floor = $v }
    } catch { }
}
$fp = 0; $fm = 0; $fpatch = 0
$bits = $floor.Split('.')
if ($bits.Count -ge 2) {
    $fp = [int]$bits[0]
    $fm = [int]$bits[1]
    if ($bits.Count -ge 3) { $fpatch = [int]$bits[2] }
}
Write-Step "Probing jsDelivr VERSION tags newer than $floor"
for ($p = $fpatch + 1; $p -le ($fpatch + 6); $p++) {
    $cand = "$fp.$fm.$p"
    $gotVer = Get-JsDelivrVersionTag ("v$cand")
    if (-not $gotVer) { break }
    Add-BaVer $gotVer
    Write-Ok "found @$cand VERSION $gotVer"
}
for ($mi = 1; $mi -le 2; $mi++) {
    $minor = $fm + $mi
    $cand = "$fp.$minor.0"
    $gotVer = Get-JsDelivrVersionTag ("v$cand")
    if ($gotVer) {
        Add-BaVer $gotVer
        Write-Ok "found @$cand VERSION $gotVer"
        for ($p = 1; $p -le 6; $p++) {
            $pcand = "$fp.$minor.$p"
            $pgot = Get-JsDelivrVersionTag ("v$pcand")
            if (-not $pgot) { break }
            Add-BaVer $pgot
            Write-Ok "found @$pcand VERSION $pgot"
        }
    }
}

$sorted = @($verHash.Keys) | Sort-Object {
    try { [version]$_ } catch { [version]'0.0.0' }
} -Descending

$ordered = New-Object System.Collections.Generic.List[string]
foreach ($v in @($sorted)) {
    [void]$ordered.Add('v' + $v)
    [void]$ordered.Add($v)
}
[void]$ordered.Add('main')
$seen = @{}
$orderedFinal = @()
foreach ($r in $ordered) {
    if ($r -and -not $seen.ContainsKey($r)) { $seen[$r] = $true; $orderedFinal += $r }
}
$ordered = $orderedFinal
Write-Host ("    try order: " + ($ordered -join ', '))
foreach ($tryRef in $ordered) {
    try {
        Write-Step "File list from jsDelivr @$tryRef"
        $api = "https://data.jsdelivr.com/v1/packages/gh/$Owner/$Repo@$tryRef"
        $metaFile = Join-Path $work 'meta.json'
        Invoke-BaGet -Uri $api -OutFile $metaFile -MinBytes 20
        $meta = Get-Content -LiteralPath $metaFile -Raw -Encoding UTF8 | ConvertFrom-Json
        $files = @(Get-JsDelivrRelPaths $meta '')
        if ($files.Count -lt 10) { throw "file list too small ($($files.Count))" }
        $src = Join-Path $work 'src'
        if (Test-Path $src) { Remove-Item $src -Recurse -Force }
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
        $srcVerFile = Join-Path $src 'VERSION'
        if (-not (Test-Path $srcVerFile)) {
            throw "jsDelivr @$tryRef has no VERSION file. Skipping this ref."
        }
        $srcVer = ((@(Get-Content $srcVerFile -TotalCount 1 -ErrorAction SilentlyContinue))[0] | ForEach-Object { $_.ToString().Trim() })
        Write-Ok "jsDelivr @$tryRef VERSION file is $srcVer"
        $want = [version]$MinVersion
        $have = $null
        try { $have = [version](($srcVer -replace '[^\d.].*','')) } catch { }
        if (-not $have) {
            throw "jsDelivr @$tryRef VERSION is unreadable ($srcVer). Skipping this ref."
        }
        if ($have -lt $want) {
            throw "jsDelivr @$tryRef is stale ($srcVer < $MinVersion). Skipping this ref."
        }
        Write-Ok "Fetched $n files @$tryRef"
        Write-Step "Overlay onto $SiteRoot (preserving data, config.php, collector.json, php.ini, secrets, logs)"
        try {
            Import-Module WebAdministration
            $st = (Get-WebAppPoolState -Name $PoolName).Value
            if ($st -ne 'Stopped') {
                Write-Host '    Stopping app pool so PHP files can be replaced'
                Stop-WebAppPool -Name $PoolName
                $w = 0
                while ($w -lt 15 -and (Get-WebAppPoolState -Name $PoolName).Value -ne 'Stopped') {
                    Start-Sleep -Seconds 1
                    $w++
                }
            }
        } catch {
            Write-Warn "Could not stop pool $PoolName : $($_.Exception.Message)"
        }
        $srcRoot = (Get-Item -LiteralPath $src).FullName.TrimEnd('\')
        $copied = 0
        Get-ChildItem -LiteralPath $srcRoot -Recurse -File -Force | ForEach-Object {
            $full = $_.FullName
            if (-not $full.StartsWith($srcRoot, [StringComparison]::OrdinalIgnoreCase)) {
                $full = (Get-Item -LiteralPath $_.FullName).FullName
            }
            if ($full.Length -le $srcRoot.Length) { return }
            $rel = $full.Substring($srcRoot.Length).TrimStart('\')
            $relFwd = ($rel -replace '\\', '/')
            if (Test-PreserveRel $relFwd) { return }
            $target = Join-Path $SiteRoot $rel
            $td = Split-Path -Parent $target
            if ($td -and -not (Test-Path -LiteralPath $td)) {
                New-Item -ItemType Directory -Force -Path $td | Out-Null
            }
            if (Test-Path -LiteralPath $target) {
                $item = Get-Item -LiteralPath $target -Force
                if ($item.IsReadOnly) { $item.IsReadOnly = $false }
            }
            [IO.File]::Copy($full, $target, $true)
            $copied++
            if ($relFwd -eq 'VERSION' -or $relFwd -eq 'app/pages.php') {
                Write-Host ("    wrote {0}" -f $target)
            }
        }
        Write-Ok "Wrote $copied files into $SiteRoot"
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
$nowVer = ''
if (Test-Path $verFile) {
    $vl = @(Get-Content $verFile -TotalCount 1 -ErrorAction SilentlyContinue)
    if ($vl.Count -gt 0 -and $vl[0]) { $nowVer = $vl[0].ToString().Trim() }
}
Write-Ok ("VERSION file $verFile = $nowVer")
$pages = Join-Path $SiteRoot 'app\pages.php'
if (Test-Path $pages) {
    $hit = Select-String -Path $pages -Pattern 'ba_flash' -SimpleMatch -Quiet
    if ($hit) { Write-Ok 'app\pages.php contains ba_flash (update-check UI is present)' }
    else { Write-Warn 'app\pages.php does not contain ba_flash - overlay did not replace Admin PHP' }
}
try {
    Import-Module WebAdministration
    Get-Website | ForEach-Object {
        $pp = [Environment]::ExpandEnvironmentVariables([string]$_.physicalPath)
        Write-Host ("    IIS site '{0}' physicalPath={1} state={2}" -f $_.Name, $pp, $_.State)
    }
} catch { }
if (-not $nowVer) {
    throw "Overlay left no VERSION file at $verFile. jsDelivr tree was not applied. Copy the BackAisle application folder onto this server (keep data, config, php.ini, secrets.env)."
}
$have = $null
try { $have = [version](($nowVer -replace '[^\d.].*','')) } catch { }
$want = [version]$MinVersion
if (-not $have) {
    throw "Overlay left unreadable VERSION '$nowVer' (need $MinVersion or newer)."
}
if ($have -lt $want) {
    throw "Overlay left VERSION $nowVer (need $MinVersion or newer). jsDelivr served a stale tree. Re-run after the GitHub tag exists on jsDelivr."
}

try {
    Import-Module WebAdministration
    $poolState = [string](Get-WebAppPoolState -Name $PoolName).Value
    if ($poolState -eq 'Stopped') {
        Start-WebAppPool -Name $PoolName
        Write-Ok "Started app pool $PoolName"
    } else {
        Restart-WebAppPool -Name $PoolName
        Write-Ok "Restarted app pool $PoolName"
    }
} catch {
    try {
        Start-WebAppPool -Name $PoolName
        Write-Ok "Started app pool $PoolName after copy"
    } catch {
        throw "Files are on disk but the IIS pool '$PoolName' is not running. In IIS Manager open Application Pools, select BackAisle, click Start."
    }
}
Write-Host ''
Write-Host '  Production overlay finished. Open http://localhost/admin.php and http://localhost/home.php' -ForegroundColor Green
Write-Host ''
