param(
    [string] $OutputDirectory = "C:\xampp\htdocs\PeerScopeSchedulerRunner"
)

$ErrorActionPreference = "Stop"

$sourcePath = Join-Path $PSScriptRoot "PeerScopeSchedulerRunner.cs"
$outputPath = Join-Path $OutputDirectory "PeerScopeSchedulerRunner.exe"
$compilerCandidates = @(
    "$env:WINDIR\Microsoft.NET\Framework64\v4.0.30319\csc.exe",
    "$env:WINDIR\Microsoft.NET\Framework\v4.0.30319\csc.exe"
)

$compilerPath = $compilerCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $compilerPath) {
    throw "C# compiler was not found. Check Microsoft .NET Framework 4.x csc.exe."
}

New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null

$compilerArguments = @(
    "/nologo",
    "/target:winexe",
    "/optimize+",
    "/out:$outputPath",
    $sourcePath
)

& $compilerPath @compilerArguments

if ($LASTEXITCODE -ne 0) {
    throw "Failed to build PeerScopeSchedulerRunner.exe."
}

Write-Host "Built: $outputPath"
