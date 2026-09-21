#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Stop BackAisleWriter and mark queued/running write jobs cancelled.
    Units already ok or fail are left unchanged.
#>
[CmdletBinding()]
param(
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [int]$JobId = 0
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-Location -LiteralPath $env:TEMP

function Test-BaExe([string]$Path) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path)) { return $false }
    if ($Path -match '(?i)\\WindowsApps\\') { return $false }
    $item = Get-Item -LiteralPath $Path -ErrorAction SilentlyContinue
    return ($item -and $item.Length -ge 2048)
}

Write-Host 'Stopping BackAisleWriter'
Stop-ScheduledTask -TaskName 'BackAisleWriter' -ErrorAction SilentlyContinue
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

$php = $null
foreach ($c in @(
    'C:\PHP\php.exe',
    'C:\php\php.exe',
    "$env:ProgramFiles\PHP\php.exe",
    "$env:ProgramFiles\PHP\v8.3\php.exe",
    "$env:ProgramFiles\PHP\v8.2\php.exe"
)) {
    if (Test-BaExe $c) { $php = $c; break }
}
if (-not $php) {
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($cmd -and (Test-BaExe $cmd.Source)) { $php = $cmd.Source }
}
$ini = Join-Path $SiteRoot 'php.ini'
if (-not $php -or -not (Test-Path -LiteralPath $ini)) {
    throw "Writer process is stopped, but php.exe or $ini was not found, so the job rows are still queued/running. Do not start BackAisleWriter until those rows are cancelled."
}

$rootFwd = ($SiteRoot -replace '\\', '/')
$cancelPhp = Join-Path $env:TEMP 'ba-cancel-jobs.php'
$phpSrc = @'
<?php
declare(strict_types=1);
require "{ROOT}/app/bootstrap.php";
require "{ROOT}/app/db.php";
$db = ba_db();
$only = (int)(getenv("BA_CANCEL_JOB") ?: 0);
if ($only > 0) {
    $st = $db->prepare("SELECT id, kind, status FROM write_jobs WHERE id=? AND status IN ('queued','running')");
    $st->execute([$only]);
    $jobs = $st->fetchAll();
} else {
    $jobs = $db->query("SELECT id, kind, status FROM write_jobs WHERE status IN ('queued','running')")->fetchAll();
}
if (!$jobs) {
    echo "no queued or running jobs\n";
    exit(0);
}
$now = gmdate("Y-m-d H:i:s");
$updT = $db->prepare("UPDATE write_job_targets SET status='cancelled', ended_at=?, error=? WHERE job_id=? AND status IN ('queued','running')");
$updJ = $db->prepare("UPDATE write_jobs SET status='cancelled', ended_at=?, error=? WHERE id=? AND status IN ('queued','running')");
$list = $db->prepare("SELECT status FROM write_job_targets WHERE job_id=?");
foreach ($jobs as $j) {
    $id = (int)$j["id"];
    $updT->execute([$now, "stopped", $id]);
    $updJ->execute([$now, "stopped so a new job can be queued", $id]);
    $list->execute([$id]);
    $bits = [];
    foreach ($list as $row) {
        $s = (string)$row["status"];
        if (!isset($bits[$s])) { $bits[$s] = 0; }
        $bits[$s]++;
    }
    $shown = [];
    foreach ($bits as $s => $n) { $shown[] = $s . "=" . $n; }
    echo "cancelled job $id " . $j["kind"] . " was " . $j["status"] . " (" . implode(", ", $shown) . ")\n";
}
'@
$phpSrc = $phpSrc.Replace('{ROOT}', $rootFwd)
Set-Content -LiteralPath $cancelPhp -Value $phpSrc -Encoding ASCII

$env:BA_CANCEL_JOB = [string]$JobId
& $php -c $ini $cancelPhp
$code = $LASTEXITCODE
Remove-Item Env:BA_CANCEL_JOB -ErrorAction SilentlyContinue
Remove-Item -LiteralPath $cancelPhp -Force -ErrorAction SilentlyContinue
if ($code -ne 0) {
    throw "Writer process was stopped, but marking jobs cancelled failed (php exit $code). Run this script again. Do not start BackAisleWriter until the jobs show cancelled."
}
Write-Host 'Writer is stopped and running/queued jobs are cancelled. Units already ok or fail were left unchanged.'
Write-Host 'Start BackAisleWriter only after writer.py is 0.5.35 or newer, then queue a new job for the cancelled units.'
