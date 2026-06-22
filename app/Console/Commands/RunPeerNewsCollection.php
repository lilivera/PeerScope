<?php

namespace App\Console\Commands;

use App\Models\CollectionError;
use App\Models\CollectionRun;
use App\Models\WatchSource;
use App\Services\Collection\NewsCollectorService;
use Illuminate\Console\Command;
use Throwable;

class RunPeerNewsCollection extends Command
{
    protected $signature = 'peerscope:run-collection {run : 収集ログID} {--source=* : 収集先ID}';

    protected $description = '作成済みの収集ログに紐づけて収集処理を実行する';

    public function handle(NewsCollectorService $collector): int
    {
        $run = CollectionRun::query()->findOrFail((int) $this->argument('run'));

        try {
            // Web側で作成済みの実行ログに、対象収集先を紐づけ直して処理する。
            $sourceIds = collect($this->option('source'))
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values();

            if ($sourceIds->isEmpty()) {
                throw new \InvalidArgumentException('収集先IDが指定されていません。');
            }

            $sources = WatchSource::query()
                ->with('company')
                ->whereIn('id', $sourceIds)
                ->get()
                // コマンド引数の順番どおりに処理し、ログの進捗表示を読みやすくする。
                ->sortBy(fn (WatchSource $source): int => $sourceIds->search($source->id))
                ->values();

            if ($sources->count() !== $sourceIds->count()) {
                throw new \RuntimeException('指定された収集先の一部が見つかりません。');
            }

            if ($runningRun = $collector->runningRunForAnySource($sources, $run)) {
                $run->update([
                    'finished_at' => now(),
                    'status' => 'warning',
                    'target_count' => $sources->count(),
                    'message' => sprintf('既に実行中の収集ログ #%d があるため、この実行は開始しませんでした。', $runningRun->id),
                ]);

                $this->warn($run->message);

                return self::SUCCESS;
            }

            $run = $collector->collectRun($run, $sources);

            $this->info($run->message ?? '収集処理が完了しました。');

            return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            // 起動直後の失敗も画面から追えるよう、実行ログとエラー詳細に残す。
            CollectionError::create([
                'collection_run_id' => $run->id,
                'watch_source_id' => null,
                'error_type' => class_basename($exception),
                'error_message' => $exception->getMessage(),
                'occurred_at' => now(),
            ]);

            $run->update([
                'finished_at' => now(),
                'status' => 'failed',
                'error_count' => $run->error_count + 1,
                'message' => '収集処理を開始できませんでした: '.$exception->getMessage(),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
