<?php

namespace App\Console\Commands;

use App\Models\WatchSource;
use App\Services\Collection\NewsCollectorService;
use Illuminate\Console\Command;

class TestPeerNewsSource extends Command
{
    protected $signature = 'peerscope:test-source {id : 収集先ID}';

    protected $description = '指定した収集先の取得テストを実行する';

    public function handle(NewsCollectorService $collector): int
    {
        // DBへ保存せず、取得・解析できる件数と先頭サンプルだけを表示する。
        $source = WatchSource::query()->findOrFail($this->argument('id'));
        $result = $collector->testSource($source);

        $this->line('HTTPステータス: '.($result['http_status'] ?? '-'));
        $this->line('取得件数: '.$result['count']);

        if ($result['error']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->table(['Title', 'URL', 'Published'], array_map(fn ($item) => [
            $item['title'] ?? '',
            $item['url'] ?? '',
            optional($item['published_at'] ?? null)->toDateTimeString(),
        ], $result['items']));

        return self::SUCCESS;
    }
}
