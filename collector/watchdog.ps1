# BackAisle collector/writer watchdog. Runs as SYSTEM via BackAisleCollectorWatch.
# Recycles writer.py when the file is newer than the process or restart_writer.flag exists.
$ErrorActionPreference = 'Stop'
$SiteRoot = 'C:\inetpub\BackAisle'
$CollectorDir = Join-Path $SiteRoot 'collector'
$LogDir = Join-Path $SiteRoot 'logs'
$Flag = Join-Path $SiteRoot 'storage\tmp\restart_writer.flag'

function Test-BaPython([string]$Path) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path)) { return $false }
    if ($Path -match '(?i)\\WindowsApps\\') { return $false }
    $item = Get-Item -LiteralPath $Path -ErrorAction SilentlyContinue
    return ($item -and $item.Length -ge 2048)
}

$py = $env:BACKAISLE_PYTHON
if (-not (Test-BaPython $py)) { $py = $null }
if (-not $py) {
    foreach ($c in @(
        "$env:ProgramFiles\Python312\python.exe",
        "$env:ProgramFiles\Python313\python.exe",
        "$env:LocalAppData\Programs\Python\Python312\python.exe",
        'C:\Python312\python.exe'
    )) {
        if (Test-BaPython $c) { $py = $c; break }
    }
}
if (-not $py) {
    $cmd = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($cmd -and (Test-BaPython $cmd.Source)) { $py = $cmd.Source }
}
if (-not $py) { throw 'watchdog: real python.exe not found (Microsoft Store stub is ignored)' }

function Get-BaPythonByScript([string]$ScriptLeaf) {
    Get-CimInstance Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and ($_.CommandLine -like "*$ScriptLeaf*") }
}

function Promote-BaPending([string]$Dir) {
    $ok = $true
    if (-not (Test-Path -LiteralPath $Dir)) { return $true }
    Get-ChildItem -LiteralPath $Dir -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Name.EndsWith('.backaisle-new') } |
        ForEach-Object {
            $dest = $_.FullName.Substring(0, $_.FullName.Length - 14)
            try {
                Copy-Item -LiteralPath $_.FullName -Destination $dest -Force
                Remove-Item -LiteralPath $_.FullName -Force -ErrorAction SilentlyContinue
            } catch {
                $ok = $false
            }
        }
    return $ok
}

function Stop-BaPythonScript([string]$ScriptLeaf, [string]$PidFile) {
    Get-BaPythonByScript -ScriptLeaf $ScriptLeaf | ForEach-Object {
        Stop-Process -Id ([int]$_.ProcessId) -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path -LiteralPath $PidFile) {
        $p = (Get-Content -LiteralPath $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
        if ($p) {
            $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$p" -ErrorAction SilentlyContinue
            if ($proc -and $proc.Name -match '^pythonw?\.exe$' -and $proc.CommandLine -like "*$ScriptLeaf*") {
                Stop-Process -Id ([int]$p) -Force -ErrorAction SilentlyContinue
            }
        }
        Remove-Item -LiteralPath $PidFile -Force -ErrorAction SilentlyContinue
    }
}

function Ensure-Proc([string]$script, [string]$pidFile, [string]$stdoutLog, [string]$stderrLog, [switch]$RecycleIfStale) {
    $leaf = Split-Path -Leaf $script
    $live = @(Get-BaPythonByScript -ScriptLeaf $leaf)
    $running = ($live.Count -gt 0)
    $wantRestart = $false
    if ($RecycleIfStale -and (Test-Path -LiteralPath $Flag)) { $wantRestart = $true }
    if ($running -and $RecycleIfStale -and (Test-Path -LiteralPath $script)) {
        $mtime = (Get-Item -LiteralPath $script).LastWriteTime
        foreach ($row in $live) {
            $gp = Get-Process -Id $row.ProcessId -ErrorAction SilentlyContinue
            if ($gp -and $gp.StartTime -and $mtime -gt $gp.StartTime) { $wantRestart = $true }
        }
    }
    if ($wantRestart) {
        Stop-BaPythonScript -ScriptLeaf $leaf -PidFile $pidFile
        $running = $false
        $live = @()
        if (Test-Path -LiteralPath $Flag) {
            Remove-Item -LiteralPath $Flag -Force -ErrorAction SilentlyContinue
        }
        Start-Sleep -Seconds 2
    }
    if ($running) { return }
    if (-not (Test-Path -LiteralPath $LogDir)) {
        New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
    }
    Start-Process -FilePath $py -ArgumentList "`"$script`"" -WorkingDirectory $SiteRoot -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog
}

$pendingOk = Promote-BaPending -Dir $CollectorDir
Ensure-Proc (Join-Path $CollectorDir 'collector.py') (Join-Path $LogDir 'collector.pid') (Join-Path $LogDir 'collector.stdout.log') (Join-Path $LogDir 'collector.stderr.log')
Ensure-Proc (Join-Path $CollectorDir 'writer.py') (Join-Path $LogDir 'writer.pid') (Join-Path $LogDir 'writer.stdout.log') (Join-Path $LogDir 'writer.stderr.log') -RecycleIfStale
if (-not $pendingOk -and -not (Test-Path -LiteralPath $Flag)) {
    New-Item -ItemType File -Force -Path $Flag | Out-Null
}
