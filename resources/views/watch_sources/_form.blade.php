@php
    $sourceType = old('source_type', $source->source_type ?? 'auto');
    $scheduleType = old('schedule_type', $source->schedule_type ?? 'interval');
    $selectedWeekdays = collect(old('schedule_weekdays', $source->schedule_weekdays ?? []))->map(fn ($value) => (string) $value)->all();
    $selectedMonthDays = collect(old('schedule_month_days', $source->schedule_month_days ?? []))->map(fn ($value) => (string) $value)->all();
    $weekdays = [
        0 => '日',
        1 => '月',
        2 => '火',
        3 => '水',
        4 => '木',
        5 => '金',
        6 => '土',
    ];
@endphp

<section class="surface p-4">
    <form method="post" action="{{ $action }}" data-watch-source-form>
        @csrf
        @if($method)
            @method($method)
        @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="company_id">会社</label>
                <select class="form-select" id="company_id" name="company_id" required>
                    <option value="">選択してください</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}" @selected(old('company_id', $source->company_id) == $company->id)>{{ $company->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="source_name">収集元名</label>
                <input class="form-control" id="source_name" name="source_name" value="{{ old('source_name', $source->source_name) }}" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="source_url">収集URL</label>
                <input class="form-control" id="source_url" name="source_url" type="url" value="{{ old('source_url', $source->source_url) }}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="source_type">収集方式</label>
                <select class="form-select" id="source_type" name="source_type" data-source-type required>
                    <option value="auto" @selected($sourceType === 'auto')>自動判定</option>
                    <option value="rss" @selected($sourceType === 'rss')>RSS / Atom</option>
                    <option value="html" @selected($sourceType === 'html')>HTML詳細指定</option>
                </select>
            </div>
            <div class="col-md-6 d-flex flex-column justify-content-end gap-2">
                <div class="form-check form-switch">
                    <input class="form-check-input" id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $source->is_active))>
                    <label class="form-check-label" for="is_active">有効</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" id="auto_ai_summary" name="auto_ai_summary" type="checkbox" value="1" @checked(old('auto_ai_summary', $source->auto_ai_summary))>
                    <label class="form-check-label" for="auto_ai_summary">ジョブ実行時にAI要約する</label>
                </div>
            </div>

            <div class="col-12">
                <hr>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="schedule_type">実行周期</label>
                <select class="form-select" id="schedule_type" name="schedule_type" data-schedule-type required>
                    <option value="interval" @selected($scheduleType === 'interval')>一定間隔</option>
                    <option value="daily" @selected($scheduleType === 'daily')>毎日</option>
                    <option value="weekly" @selected($scheduleType === 'weekly')>毎週</option>
                    <option value="monthly" @selected($scheduleType === 'monthly')>毎月</option>
                </select>
            </div>
            <div class="col-md-4" data-schedule-panel="interval">
                <label class="form-label" for="crawl_interval_minutes">間隔 分</label>
                <input class="form-control" id="crawl_interval_minutes" name="crawl_interval_minutes" type="number" min="1" value="{{ old('crawl_interval_minutes', $source->crawl_interval_minutes ?: 60) }}">
            </div>
            <div class="col-md-4" data-schedule-time-panel>
                <label class="form-label" for="schedule_time">実行時刻</label>
                <input class="form-control" id="schedule_time" name="schedule_time" type="time" value="{{ old('schedule_time', $source->schedule_time ?: '09:00') }}">
            </div>
            <div class="col-12" data-schedule-panel="weekly">
                <label class="form-label d-block">曜日</label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($weekdays as $value => $label)
                        <input class="btn-check" id="schedule_weekday_{{ $value }}" name="schedule_weekdays[]" type="checkbox" value="{{ $value }}" @checked(in_array((string) $value, $selectedWeekdays, true))>
                        <label class="btn btn-outline-secondary btn-sm" for="schedule_weekday_{{ $value }}">{{ $label }}</label>
                    @endforeach
                </div>
            </div>
            <div class="col-12" data-schedule-panel="monthly">
                <label class="form-label d-block">日付</label>
                <div class="schedule-check-grid">
                    @for($day = 1; $day <= 31; $day++)
                        <input class="btn-check" id="schedule_month_day_{{ $day }}" name="schedule_month_days[]" type="checkbox" value="{{ $day }}" @checked(in_array((string) $day, $selectedMonthDays, true))>
                        <label class="btn btn-outline-secondary btn-sm" for="schedule_month_day_{{ $day }}">{{ $day }}</label>
                    @endfor
                </div>
            </div>

            <div class="col-12" data-html-settings>
                <hr>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="list_selector">一覧行セレクタ</label>
                        <input class="form-control" id="list_selector" name="list_selector" value="{{ old('list_selector', $source->list_selector) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="title_selector">タイトルセレクタ</label>
                        <input class="form-control" id="title_selector" name="title_selector" value="{{ old('title_selector', $source->title_selector) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="url_selector">URLセレクタ</label>
                        <input class="form-control" id="url_selector" name="url_selector" value="{{ old('url_selector', $source->url_selector) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="date_selector">日付セレクタ</label>
                        <input class="form-control" id="date_selector" name="date_selector" value="{{ old('date_selector', $source->date_selector) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="body_selector">本文セレクタ</label>
                        <input class="form-control" id="body_selector" name="body_selector" value="{{ old('body_selector', $source->body_selector) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ $button }}
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('watch-sources.index') }}">戻る</a>
        </div>
    </form>
</section>
