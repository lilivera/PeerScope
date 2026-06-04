<?php

namespace App\Support;

use Symfony\Component\Process\PhpExecutableFinder;

class BackgroundArtisanRunner
{
    /**
     * Webリクエストを待たせずに、artisanコマンドを別プロセスで起動する。
     *
     * @param  array<int, string|int>  $arguments
     */
    public function run(string $command, array $arguments = []): bool
    {
        $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
        $artisan = base_path('artisan');

        if (! is_file($artisan)) {
            return false;
        }

        // PHPパスや引数に空白があっても壊れないよう、すべてシェル用に引用する。
        $parts = array_map(
            fn (string|int $part): string => escapeshellarg((string) $part),
            array_merge([$php, $artisan, $command], $arguments),
        );

        $shellCommand = implode(' ', $parts);
        // WindowsのXAMPPではstart /B、Unix系では末尾&でバックグラウンド化する。
        $backgroundCommand = $this->isWindows()
            ? 'start /B "" '.$shellCommand.' > NUL 2>&1'
            : $shellCommand.' > /dev/null 2>&1 &';

        $handle = @popen($backgroundCommand, 'r');

        if ($handle === false) {
            return false;
        }

        pclose($handle);

        return true;
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
