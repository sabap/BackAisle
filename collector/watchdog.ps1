$py = $env:BACKAISLE_PYTHON
if (-not $py -or $py -match '(?i)\\WindowsApps\\' -or -not (Test-Path -LiteralPath $py)) {
    $py = $null
    foreach ($c in @(
        "$env:ProgramFiles\Python312\python.exe",
        "$env:ProgramFiles\Python313\python.exe",
        "$env:LocalAppData\Programs\Python\Python312\python.exe",
        'C:\Python312\python.exe'
    )) {
        if ($c -and (Test-Path -LiteralPath $c)) {
            $item = Get-Item -LiteralPath $c -ErrorAction SilentlyContinue
            if ($item -and $item.Length -ge 2048) { $py = $c; break }
        }
    }
}
if (-not $py) {
    $cmd = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source -notmatch '(?i)\\WindowsApps\\') { $py = $cmd.Source }
}
if (-not $py) { throw 'watchdog: real python.exe not found (Microsoft Store stub is ignored)' }
function Ensure-Proc($script, $pidFile, $stdoutLog, $stderrLog) {
    $running = $false
    if (Test-Path $pidFile) {
        $p = Get-Content $pidFile -ErrorAction SilentlyContinue
        if ($p -and (Get-Process -Id $p -ErrorAction SilentlyContinue)) { $running = $true }
    }
    if (-not $running) {
        Start-Process -FilePath $py -ArgumentList $script -WindowStyle Hidden -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog
    }
}
Ensure-Proc 'C:\inetpub\BackAisle\collector\collector.py' 'C:\inetpub\BackAisle\logs\collector.pid' 'C:\inetpub\BackAisle\logs\collector.stdout.log' 'C:\inetpub\BackAisle\logs\collector.stderr.log'
Ensure-Proc 'C:\inetpub\BackAisle\collector\writer.py' 'C:\inetpub\BackAisle\logs\writer.pid' 'C:\inetpub\BackAisle\logs\writer.stdout.log' 'C:\inetpub\BackAisle\logs\writer.stderr.log'
