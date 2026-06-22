<?php

namespace App\Support;

use App\Models\CollectionRun;

class CollectionProcessTerminator
{
    public function terminate(CollectionRun $run): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? $this->terminateWindows($run)
            : $this->terminateUnix($run);
    }

    private function terminateWindows(CollectionRun $run): bool
    {
        $script = <<<'POWERSHELL'
$ProgressPreference = 'SilentlyContinue';
$runId = __RUN_ID__;
$pattern = '(^|\s|")' + [regex]::Escape([string] $runId) + '(\s|"|$)';
$targets = Get-CimInstance Win32_Process |
    Where-Object {
        $_.Name -like 'php*' -and
        $_.CommandLine -match 'peerscope:run-collection' -and
        $_.CommandLine -match $pattern
    };
$targets | ForEach-Object {
    Stop-Process -Id $_.ProcessId -Force;
    $_.ProcessId;
};
POWERSHELL;

        $script = str_replace('__RUN_ID__', (string) $run->id, $script);
        $encodedScript = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $command = 'powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -EncodedCommand '.$encodedScript.' 2>NUL';

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        return $exitCode === 0 && $output !== [];
    }

    private function terminateUnix(CollectionRun $run): bool
    {
        if (! function_exists('shell_exec') || ! function_exists('posix_kill')) {
            return false;
        }

        $processes = (string) @shell_exec('ps -eo pid=,args=');
        $terminated = false;

        foreach (explode("\n", $processes) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, 'peerscope:run-collection')) {
                continue;
            }

            if (! preg_match('/^(\d+)\s+(.+)$/', $line, $matches)) {
                continue;
            }

            if (! preg_match('/(?:^|\s|")'.preg_quote((string) $run->id, '/').'(?:\s|"|$)/', $matches[2])) {
                continue;
            }

            $terminated = @posix_kill((int) $matches[1], SIGTERM) || $terminated;
        }

        return $terminated;
    }
}
