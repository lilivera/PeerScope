<?php

namespace App\Console\Commands;

use App\Services\Collection\NewsCollectorService;
use Illuminate\Console\Command;

class CollectPeerNews extends Command
{
    protected $signature = 'peerscope:collect';

    protected $description = '有効な収集先を対象に同業他社の新着情報を収集する';

    public function handle(NewsCollectorService $collector): int
    {
        // スケジューラから呼ばれる通常収集。対象判定はサービス側に集約する。
        $run = $collector->collectDue();

        if ($run === null) {
            $this->info('実行対象の収集先はありません。');

            return self::SUCCESS;
        }

        $this->info($run->message ?? '収集処理が完了しました。');

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
