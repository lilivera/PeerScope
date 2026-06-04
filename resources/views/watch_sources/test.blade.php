@extends('layouts.app')

@section('title', '収集テスト - PeerScope')
@section('page_title', '収集テスト')

@section('content')
    <div class="mb-3">
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('watch-sources.index') }}">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>戻る
            </a>
            <form method="post" action="{{ route('watch-sources.collect', $source) }}">
                @csrf
                <button class="btn btn-success" type="submit">
                    <i class="bi bi-cloud-download me-1" aria-hidden="true"></i>収集実行
                </button>
            </form>
        </div>
    </div>

    <section class="surface p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="meta-label">会社</div>
                <div>{{ $source->company?->name }}</div>
            </div>
            <div class="col-md-3">
                <div class="meta-label">収集元名</div>
                <div>{{ $source->source_name }}</div>
            </div>
            <div class="col-md-3">
                <div class="meta-label">HTTPステータス</div>
                <div>{{ $result['http_status'] ?? '-' }}</div>
            </div>
            <div class="col-md-3">
                <div class="meta-label">取得件数</div>
                <div>{{ number_format($result['count']) }}</div>
            </div>
        </div>
        @if($result['error'])
            <div class="alert alert-danger mt-4 mb-0">{{ $result['error'] }}</div>
        @endif
    </section>

    <section class="surface p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>タイトル</th>
                    <th>URL</th>
                    <th>掲載日</th>
                </tr>
                </thead>
                <tbody>
                @forelse($result['items'] as $item)
                    <tr>
                        <td>{{ $item['title'] ?? '' }}</td>
                        <td><a href="{{ $item['url'] ?? '#' }}" target="_blank" rel="noopener noreferrer">{{ $item['url'] ?? '' }}</a></td>
                        <td>{{ optional($item['published_at'] ?? null)->format('Y-m-d H:i') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty-state">表示できるテスト結果はありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
