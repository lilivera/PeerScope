<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WatchSource;
use App\Services\Collection\NewsCollectorService;
use App\Support\BackgroundArtisanRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WatchSourceController extends Controller
{
    public function index()
    {
        return view('watch_sources.index', [
            'sources' => WatchSource::query()
                ->with('company')
                ->withCount('collectedItems')
                ->orderByDesc('is_active')
                ->orderBy('source_name')
                ->paginate(50),
        ]);
    }

    public function create()
    {
        return view('watch_sources.create', [
            'source' => new WatchSource([
                'source_type' => 'auto',
                'crawl_interval_minutes' => 60,
                'schedule_type' => 'daily',
                'schedule_time' => '09:00',
                'is_active' => true,
            ]),
            'companies' => $this->companies(),
        ]);
    }

    public function store(Request $request)
    {
        WatchSource::create($this->validatedData($request));

        return redirect()->route('watch-sources.index')->with('status', '収集先を登録しました。');
    }

    public function edit(WatchSource $watchSource)
    {
        return view('watch_sources.edit', [
            'source' => $watchSource,
            'companies' => $this->companies(),
        ]);
    }

    public function update(Request $request, WatchSource $watchSource)
    {
        $watchSource->update($this->validatedData($request));

        return redirect()->route('watch-sources.index')->with('status', '収集先を更新しました。');
    }

    public function test(WatchSource $watchSource, NewsCollectorService $collector)
    {
        // テスト実行はDBへ保存せず、収集設定で何件読めるかだけを確認する。
        $watchSource->load('company');

        return view('watch_sources.test', [
            'source' => $watchSource,
            'result' => $collector->testSource($watchSource),
        ]);
    }

    public function collect(WatchSource $watchSource, NewsCollectorService $collector, BackgroundArtisanRunner $backgroundRunner)
    {
        $sources = collect([$watchSource->load('company')]);

        if ($runningRun = $collector->runningRunForSource($watchSource)) {
            return redirect()
                ->route('collection-runs.show', $runningRun)
                ->with('status', 'この収集先はすでに実行中です。実行中の収集ログを表示します。');
        }

        // 先に実行ログを作成し、画面をすぐ詳細へ遷移できるようにする。
        $run = $collector->createRunForSources(
            $sources,
            $watchSource->source_name.' の収集を起動待ちです。',
        );

        if (app()->environment('testing')) {
            // Featureテストでは別プロセスを起動せず、同じPHPプロセス内で完了させる。
            $run = $collector->collectRun($run, $sources);

            return redirect()
                ->route('collection-runs.show', $run)
                ->with('status', '収集を実行しました。'.$run->message);
        }

        $started = $backgroundRunner->run('peerscope:run-collection', [
            $run->id,
            '--source='.$watchSource->id,
        ]);

        if (! $started) {
            // Windows/XAMPP環境でバックグラウンド起動できない場合も、手実行自体は失敗させない。
            set_time_limit(0);

            $run = $collector->collectRun($run, $sources);

            return redirect()
                ->route('collection-runs.show', $run)
                ->with('status', 'バックグラウンド起動に失敗したため、通常実行しました。'.$run->message);
        }

        return redirect()
            ->route('collection-runs.show', $run)
            ->with('status', '収集を開始しました。進捗はこの画面で自動更新されます。');
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'source_name' => ['required', 'string', 'max:255'],
            'source_url' => ['required', 'url', 'max:2048'],
            'source_type' => ['required', Rule::in(['auto', 'rss', 'html'])],
            'list_selector' => ['nullable', 'required_if:source_type,html', 'string', 'max:1024'],
            'title_selector' => ['nullable', 'required_if:source_type,html', 'string', 'max:1024'],
            'url_selector' => ['nullable', 'required_if:source_type,html', 'string', 'max:1024'],
            'date_selector' => ['nullable', 'string', 'max:1024'],
            'body_selector' => ['nullable', 'string', 'max:1024'],
            'crawl_interval_minutes' => ['nullable', 'required_if:schedule_type,interval', 'integer', 'min:1'],
            'schedule_type' => ['required', Rule::in(['interval', 'daily', 'weekly', 'monthly'])],
            'schedule_time' => ['nullable', 'required_unless:schedule_type,interval', 'date_format:H:i'],
            'schedule_weekdays' => ['nullable', 'required_if:schedule_type,weekly', 'array'],
            'schedule_weekdays.*' => ['integer', 'between:0,6'],
            'schedule_month_days' => ['nullable', 'required_if:schedule_type,monthly', 'array'],
            'schedule_month_days.*' => ['integer', 'between:1,31'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $this->normalizeScheduleData($data);
    }

    private function normalizeScheduleData(array $data): array
    {
        $data['crawl_interval_minutes'] = (int) (($data['crawl_interval_minutes'] ?? null) ?: 60);

        if ($data['schedule_type'] === 'interval') {
            $data['schedule_time'] = null;
            $data['schedule_weekdays'] = null;
            $data['schedule_month_days'] = null;

            return $data;
        }

        $data['schedule_time'] = $data['schedule_time'] ?: '09:00';
        $data['schedule_weekdays'] = $data['schedule_type'] === 'weekly'
            ? $this->normalizeNumberList($data['schedule_weekdays'] ?? [])
            : null;
        $data['schedule_month_days'] = $data['schedule_type'] === 'monthly'
            ? $this->normalizeNumberList($data['schedule_month_days'] ?? [])
            : null;

        return $data;
    }

    private function normalizeNumberList(array $values): array
    {
        return collect($values)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function companies()
    {
        return Company::query()->where('is_active', true)->orderBy('name')->get();
    }
}
