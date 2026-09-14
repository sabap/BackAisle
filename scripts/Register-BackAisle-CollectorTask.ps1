#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Register Task Scheduler jobs for the BackAisle SNMPv3 collector and writer.
#>
[CmdletBinding()]
param(
    [string]$SiteRoot = 'C:\inetpub\BackAisle',
    [string]$PythonExe = ''
)

$ErrorActionPreference = 'Stop'
if (-not $PythonExe) {
    $cmd = Get-Command python -ErrorAction SilentlyContinue
    if ($cmd) { $PythonExe = $cmd.Source }
}
if (-not $PythonExe -or -not (Test-Path $PythonExe)) {
    foreach ($c in @(
        "$env:ProgramFiles\Python312\python.exe",
        "$env:LocalAppData\Programs\Python\Python312\python.exe"
    )) {
        if (Test-Path $c) { $PythonExe = $c; break }
    }
}
if (-not $PythonExe -or -not (Test-Path $PythonExe)) {
    throw 'Python not found. Install Python 3.12+ and re-run.'
}

$collector = Join-Path $SiteRoot 'collector\collector.py'
$writer = Join-Path $SiteRoot 'collector\writer.py'
$watchdog = Join-Path $SiteRoot 'collector\watchdog.ps1'
if (-not (Test-Path $collector)) { throw "Missing $collector" }

function Register-BaTask([string]$Name, [string]$Exe, [string]$Args, [string]$Every) {
    $action = New-ScheduledTaskAction -Execute $Exe -Argument $Args -WorkingDirectory $SiteRoot
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration ([TimeSpan]::MaxValue)
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 0)
    Unregister-ScheduledTask -TaskName $Name -Confirm:$false -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName $Name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Write-Host "Registered $Name"
}

# Boot start of collector + writer
$boot = New-ScheduledTaskTrigger -AtStartup
$bootAction = New-ScheduledTaskAction -Execute $PythonExe -Argument "`"$collector`"" -WorkingDirectory $SiteRoot
$prin = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$set = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 0)
Unregister-ScheduledTask -TaskName 'BackAisleCollector' -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName 'BackAisleCollector' -Action $bootAction -Trigger $boot -Principal $prin -Settings $set -Force | Out-Null
$wAction = New-ScheduledTaskAction -Execute $PythonExe -Argument "`"$writer`"" -WorkingDirectory $SiteRoot
Unregister-ScheduledTask -TaskName 'BackAisleWriter' -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName 'BackAisleWriter' -Action $wAction -Trigger $boot -Principal $prin -Settings $set -Force | Out-Null

if (Test-Path $watchdog) {
    $wdAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$watchdog`""
    $wdTrig = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration ([TimeSpan]::MaxValue)
    Unregister-ScheduledTask -TaskName 'BackAisleCollectorWatch' -Confirm:$false -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName 'BackAisleCollectorWatch' -Action $wdAction -Trigger $wdTrig -Principal $prin -Settings $set -Force | Out-Null
}

Write-Host "BackAisle collector/writer tasks registered. Python: $PythonExe"
Start-ScheduledTask -TaskName 'BackAisleCollector' -ErrorAction SilentlyContinue
Start-ScheduledTask -TaskName 'BackAisleWriter' -ErrorAction SilentlyContinue
