<?php

namespace App\Http\Controllers;

use App\Models\CollectionRun;
use App\Models\WatchSource;
use App\Support\CollectionProcessTerminator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CollectionRunController extends Controller
{
    public function index()
    {
        return view('collection_runs.index', [
            'runs' => CollectionRun::query()
                ->with('targetSources')
                ->withCount('errors')
                ->where('target_count', '>', 0)
                ->latest('started_at')
                ->paginate(50),
        ]);
    }

    public function downloadCsv(): StreamedResponse
    {
        $filename = 'collection-runs-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // Excelで開いた時に日本語が文字化けしにくいようUTF-8 BOMを付与する。
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ログID',
                '実行開始日時',
                '実行終了日時',
                '対象収集先',
                'ステータス',
                '対象件数',
                '新規件数',
                '更新件数',
                'エラー件数',
                'メッセージ',
            ]);

            CollectionRun::query()
                ->with('targetSources')
                ->where('target_count', '>', 0)
                ->latest('started_at')
                ->chunk(500, function ($runs) use ($handle): void {
                    $runs->each(function (CollectionRun $run) use ($handle): void {
                        fputcsv($handle, [
                            $run->id,
                            $run->started_at?->format('Y-m-d H:i:s') ?? '',
                            $run->finished_at?->format('Y-m-d H:i:s') ?? '',
                            $run->targetSummary(100),
                            $run->statusLabel(),
                            $run->target_count,
                            $run->created_count,
                            $run->updated_count,
                            $run->error_count,
                            $run->message ?? '',
                        ]);
                    });
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function show(CollectionRun $collectionRun)
    {
        // 詳細画面では、対象収集先とエラーが出た収集元をまとめて表示する。
        $collectionRun->load(['targetSources', 'errors.watchSource.company']);

        return view('collection_runs.show', ['run' => $collectionRun]);
    }

    public function cancel(CollectionRun $collectionRun, CollectionProcessTerminator $terminator)
    {
        if ($collectionRun->status !== 'running') {
            return redirect()
                ->route('collection-runs.show', $collectionRun)
                ->with('status', 'この収集ログは既に終了しています。');
        }

        $terminated = $terminator->terminate($collectionRun);

        $collectionRun->update([
            'finished_at' => now(),
            'status' => 'cancelled',
            'message' => $terminated
                ? '中断しました。実行中の収集プロセスを停止しました。'
                : '中断要求を受け付けました。現在の処理単位が終わり次第停止します。',
        ]);

        $this->touchTargetSources($collectionRun);

        return redirect()
            ->route('collection-runs.show', $collectionRun)
            ->with('status', '収集ログを中断しました。');
    }

    private function touchTargetSources(CollectionRun $collectionRun): void
    {
        $sourceIds = $collectionRun->targetSources()
            ->whereNotNull('watch_source_id')
            ->pluck('watch_source_id');

        if ($sourceIds->isNotEmpty()) {
            WatchSource::query()->whereKey($sourceIds)->update(['last_crawled_at' => now()]);
        }
    }
}
