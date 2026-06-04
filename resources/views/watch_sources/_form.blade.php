<section class="surface p-4">
    <form method="post" action="{{ $action }}">
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
            <div class="col-md-4">
                <label class="form-label" for="source_type">収集方式</label>
                <select class="form-select" id="source_type" name="source_type" required>
                    <option value="rss" @selected(old('source_type', $source->source_type) === 'rss')>rss</option>
                    <option value="html" @selected(old('source_type', $source->source_type) === 'html')>html</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="crawl_interval_minutes">収集間隔 分</label>
                <input class="form-control" id="crawl_interval_minutes" name="crawl_interval_minutes" type="number" min="1" value="{{ old('crawl_interval_minutes', $source->crawl_interval_minutes) }}" required>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $source->is_active))>
                    <label class="form-check-label" for="is_active">有効</label>
                </div>
            </div>
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

        <div class="d-flex gap-2 mt-4">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ $button }}
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('watch-sources.index') }}">戻る</a>
        </div>
    </form>
</section>
