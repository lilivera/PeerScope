param(
    [string] $TaskName = "PeerScope Laravel Scheduler Direct",
    [string] $ProjectPath = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path,
    [string] $PhpPath = "C:\xampp\php\php-win.exe",
    [string] $BlockedRunnerTaskName = "PeerScope Laravel Scheduler",
    [bool] $DisableBlockedRunnerTask = $true
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $PhpPath)) {
    throw "PHP executable was not found: $PhpPath"
}

if (-not (Test-Path (Join-Path $ProjectPath "artisan"))) {
    throw "Laravel artisan was not found in project path: $ProjectPath"
}

$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).Date `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

$action = New-ScheduledTaskAction `
    -Execute $PhpPath `
    -Argument "artisan schedule:run" `
    -WorkingDirectory $ProjectPath

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -Hidden `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description "Runs PeerScope Laravel Scheduler every minute by php-win.exe without opening a command window." `
    -Force | Out-Null

if ($DisableBlockedRunnerTask) {
    $blockedTask = Get-ScheduledTask -TaskName $BlockedRunnerTaskName -ErrorAction SilentlyContinue

    if ($blockedTask) {
        Disable-ScheduledTask -TaskName $BlockedRunnerTaskName | Out-Null
    }
}

Write-Host "Registered task: $TaskName"
Write-Host "PHP: $PhpPath"
Write-Host "Working directory: $ProjectPath"

if ($DisableBlockedRunnerTask) {
    Write-Host "Disabled blocked runner task if it existed: $BlockedRunnerTaskName"
}
