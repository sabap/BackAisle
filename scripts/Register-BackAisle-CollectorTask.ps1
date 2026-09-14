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
function Test-BaPython([string]$Path) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path)) { return $false }
    if ($Path -match '(?i)\\WindowsApps\\') { return $false }
    $item = Get-Item -LiteralPath $Path -ErrorAction SilentlyContinue
    return ($item -and $item.Length -ge 2048)
}
if ($PythonExe -and -not (Test-BaPython $PythonExe)) { $PythonExe = '' }
if (-not $PythonExe) {
    foreach ($c in @(
        "$env:ProgramFiles\Python312\python.exe",
        "$env:ProgramFiles\Python313\python.exe",
        "$env:LocalAppData\Programs\Python\Python312\python.exe",
        "$env:LocalAppData\Programs\Python\Python313\python.exe"
    )) {
        if (Test-BaPython $c) { $PythonExe = $c; break }
    }
}
if (-not $PythonExe) {
    $cmd = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($cmd -and (Test-BaPython $cmd.Source)) { $PythonExe = $cmd.Source }
}
if (-not $PythonExe) {
    throw 'Python not found. Install Python 3.12+ from python.org (not the Microsoft Store stub) and re-run.'
}

$collector = Join-Path $SiteRoot 'collector\collector.py'
$writer = Join-Path $SiteRoot 'collector\writer.py'
$watchdog = Join-Path $SiteRoot 'collector\watchdog.ps1'
if (-not (Test-Path $collector)) { throw "Missing $collector" }

function New-BaRepeatTrigger([int]$Minutes = 5) {
    # Do not use [TimeSpan]::MaxValue -- it becomes P99999999DT23H59M59S (0x80041318).
    return New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) `
        -RepetitionInterval (New-TimeSpan -Minutes $Minutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)
}

function Register-BaTask([string]$Name, [string]$Exe, [string]$Args, [string]$Every) {
    $action = New-ScheduledTaskAction -Execute $Exe -Argument $Args -WorkingDirectory $SiteRoot
    $trigger = New-BaRepeatTrigger -Minutes 5
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
    try {
        $wdAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$watchdog`"" -WorkingDirectory $SiteRoot
        $wdTrig = New-BaRepeatTrigger -Minutes 5
        $wdBoot = New-ScheduledTaskTrigger -AtStartup
        Unregister-ScheduledTask -TaskName 'BackAisleCollectorWatch' -Confirm:$false -ErrorAction SilentlyContinue
        Register-ScheduledTask -TaskName 'BackAisleCollectorWatch' -Action $wdAction -Trigger @($wdBoot, $wdTrig) -Principal $prin -Settings $set -Force | Out-Null
        Write-Host 'Registered BackAisleCollectorWatch'
    } catch {
        Write-Warning "BackAisleCollectorWatch not registered: $($_.Exception.Message)"
    }
}

Write-Host "BackAisle collector/writer tasks registered. Python: $PythonExe"
Start-ScheduledTask -TaskName 'BackAisleCollector' -ErrorAction SilentlyContinue
Start-ScheduledTask -TaskName 'BackAisleWriter' -ErrorAction SilentlyContinue
