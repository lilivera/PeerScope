<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../bootstrap/peerscope_runtime.php';

peerscope_prepare_windows_runtime();

// メンテナンスモードかどうかを確認する。
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Composerが用意した自動読み込みを登録する。
require __DIR__.'/../vendor/autoload.php';

// Laravelを起動し、HTTPリクエストを処理する。
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
