# Registers a Windows Task Scheduler task that starts the ViLoCare scheduler at logon, hidden.
# Run once on the clinic machine:  powershell -ExecutionPolicy Bypass -File clinic\install-scheduler-task.ps1
# Remove with:                     powershell -ExecutionPolicy Bypass -File clinic\install-scheduler-task.ps1 -Uninstall

param([switch]$Uninstall)

$taskName = 'ViLoCare Scheduler'

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Removed task '$taskName'."
    return
}

$bat = Join-Path $PSScriptRoot 'start-scheduler.bat'
$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c `"$bat`"" -WorkingDirectory (Split-Path $PSScriptRoot)
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
    -Description 'Runs php artisan schedule:work for ViLoCare (sync, SMS queue, due reminders).' -Force | Out-Null

Write-Host "Registered '$taskName' (starts at logon as $env:USERNAME). Start it now with:  Start-ScheduledTask -TaskName '$taskName'"
