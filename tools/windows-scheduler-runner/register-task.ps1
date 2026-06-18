param(
    [string] $TaskName = "PeerScope Laravel Scheduler",
    [string] $ProjectPath = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path,
    [string] $RunnerDirectory = "C:\xampp\htdocs\PeerScopeSchedulerRunner"
)

$ErrorActionPreference = "Stop"

$buildScriptPath = Join-Path $PSScriptRoot "build.ps1"
$existingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if ($existingTask -and $existingTask.State -eq "Running") {
    Stop-ScheduledTask -TaskName $TaskName
    Start-Sleep -Seconds 2
}

& $buildScriptPath -OutputDirectory $RunnerDirectory

if ($LASTEXITCODE -ne 0) {
    throw "Failed to build PeerScopeSchedulerRunner.exe. Task registration was stopped."
}

$runnerPath = Join-Path $RunnerDirectory "PeerScopeSchedulerRunner.exe"
$triggerStart = (Get-Date).Date
$action = New-ScheduledTaskAction -Execute $runnerPath -WorkingDirectory $ProjectPath
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At $triggerStart `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description "Runs PeerScope Laravel Scheduler every minute without opening a command window." `
    -Force | Out-Null

Write-Host "Registered task: $TaskName"
Write-Host "Runner: $runnerPath"
Write-Host "Working directory: $ProjectPath"
