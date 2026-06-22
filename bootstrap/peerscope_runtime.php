<?php

if (! function_exists('peerscope_prepare_windows_runtime')) {
    /**
     * Windowsで通常実行する際の余分なコンソール起動を抑止する。
     */
    function peerscope_prepare_windows_runtime(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        peerscope_set_console_size_environment();

        if (! peerscope_is_phpunit_context()) {
            peerscope_skip_collision_phpunit_autoload();
        }
    }
}

if (! function_exists('peerscope_set_console_size_environment')) {
    /**
     * Symfony Consoleの端末サイズ判定でstty/mode CONが起動しないようにする。
     */
    function peerscope_set_console_size_environment(): void
    {
        putenv('COLUMNS='.(getenv('COLUMNS') ?: '120'));
        putenv('LINES='.(getenv('LINES') ?: '40'));
    }
}

if (! function_exists('peerscope_is_phpunit_context')) {
    /**
     * PHPUnitやartisan testでは、開発用のテスト出力初期化をそのまま有効にする。
     */
    function peerscope_is_phpunit_context(): bool
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            return true;
        }

        foreach ($_SERVER['argv'] ?? [] as $argument) {
            $normalized = strtolower(str_replace('\\', '/', (string) $argument));

            if ($normalized === 'test' || str_contains($normalized, 'phpunit')) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('peerscope_skip_collision_phpunit_autoload')) {
    /**
     * 通常実行ではCollisionのPHPUnit向け初期化を読み飛ばし、Gitの一瞬起動を防ぐ。
     */
    function peerscope_skip_collision_phpunit_autoload(): void
    {
        $autoloadFiles = __DIR__.'/../vendor/composer/autoload_files.php';

        if (! is_file($autoloadFiles)) {
            return;
        }

        foreach (require $autoloadFiles as $identifier => $file) {
            $normalized = str_replace('\\', '/', (string) $file);

            if (str_ends_with($normalized, '/nunomaduro/collision/src/Adapters/Phpunit/Autoload.php')) {
                $GLOBALS['__composer_autoload_files'][$identifier] = true;
            }
        }
    }
}
