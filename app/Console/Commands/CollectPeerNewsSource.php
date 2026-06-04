<?php

namespace App\Console\Commands;

use App\Models\WatchSource;
use App\Services\Collection\NewsCollectorService;
use Illuminate\Console\Command;

class CollectPeerNewsSource extends Command
{
    protected $signature = 'peerscope:collect-source {id : 収集先ID}';

    protected $description = '指定した収集先のみ新着情報を収集する';

    public function handle(NewsCollectorService $collector): int
    {
        // 調査や再収集で使う単体実行コマンド。
        $source = WatchSource::query()->findOrFail($this->argument('id'));
        $run = $collector->collectSource($source);

        $this->info($run->message ?? '収集処理が完了しました。');

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
