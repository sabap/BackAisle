$py = $env:BACKAISLE_PYTHON
if (-not $py) {
    $cmd = Get-Command python -ErrorAction SilentlyContinue
    if ($cmd) { $py = $cmd.Source }
}
if (-not $py) { $py = 'C:\Python312\python.exe' }
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
