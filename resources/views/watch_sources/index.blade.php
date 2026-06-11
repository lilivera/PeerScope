@extends('layouts.app')

@section('title', '収集先管理 - PeerScope')
@section('page_title', '収集先管理')

@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a class="btn btn-primary" href="{{ route('watch-sources.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>登録
        </a>
    </div>

    <section class="surface p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>会社</th>
                    <th>収集元名</th>
                    <th>方式</th>
                    <th>実行周期</th>
                    <th>最終収集日時</th>
                    <th>有効区分</th>
                    <th class="text-end">新着</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($sources as $source)
                    <tr>
                        <td>{{ $source->company?->name }}</td>
                        <td>
                            <div class="fw-semibold">{{ $source->source_name }}</div>
                            <a class="small" href="{{ $source->source_url }}" target="_blank" rel="noopener noreferrer">{{ $source->source_url }}</a>
                        </td>
                        <td><span class="badge badge-soft">{{ $source->sourceTypeLabel() }}</span></td>
                        <td>{{ $source->scheduleLabel() }}</td>
                        <td>{{ $source->last_crawled_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td>
                            <span class="badge {{ $source->is_active ? 'badge-read' : 'badge-soft' }}">
                                {{ $source->is_active ? '有効' : '無効' }}
                            </span>
                        </td>
                        <td class="text-end">{{ number_format($source->collected_items_count) }}</td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <form method="post" action="{{ route('watch-sources.test', $source) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="収集テスト">
                                        <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                                    </button>
                                </form>
                                <form method="post" action="{{ route('watch-sources.collect', $source) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success" type="submit" title="収集実行">
                                        <i class="bi bi-cloud-download" aria-hidden="true"></i>
                                    </button>
                                </form>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('watch-sources.edit', $source) }}" title="編集">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty-state">収集先はまだ登録されていません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $sources->links() }}
    </section>
@endsection
