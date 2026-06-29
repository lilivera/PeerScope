@extends('layouts.app')

@section('title', 'ダッシュボード - PeerScope')
@section('page_title', 'ダッシュボード')

@section('content')
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card p-3 h-100">
                <div class="stat-card-header">
                    <div class="meta-label">本日の新着件数</div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-value">{{ number_format($todayCount) }}</div>
                </div>
                <div class="stat-card-footer"></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card p-3 h-100">
                <div class="stat-card-header">
                    <div class="meta-label">未読件数</div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-value">{{ number_format($unreadCount) }}</div>
                </div>
                <div class="stat-card-footer"></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card p-3 h-100">
                <div class="stat-card-header">
                    <div class="meta-label">直近収集日時</div>
                    <span class="badge {{ $latestRun ? $latestRun->statusBadgeClass() : 'text-bg-secondary' }}">
                        {{ $latestRun?->statusLabel() ?? '未実行' }}
                    </span>
                </div>
                <div class="stat-card-body">
                    <div class="stat-value stat-value-sm">{{ optional($latestRun?->finished_at)->format('Y-m-d H:i') ?? '-' }}</div>
                </div>
                <div class="stat-card-footer">
                    <span class="text-muted small">最終完了ログ</span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card p-3 h-100">
                <div class="stat-card-header">
                    <div class="meta-label">収集エラー件数</div>
                </div>
                <div class="stat-card-body">
                    <div class="stat-value">{{ number_format($errorCount) }}</div>
                </div>
                <div class="stat-card-footer">
                    @if(auth()->user()->isAdmin())
                        <a class="small fw-semibold" href="{{ route('collection-runs.index') }}">
                            ログを確認
                            <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <section class="surface p-3">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h5 mb-0">会社別新着件数</h2>
                    <span class="text-muted small">直近7日</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>会社名</th>
                            <th class="text-end">件数</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($companyCounts as $company)
                            <tr>
                                <td>{{ $company->name }}</td>
                                <td class="text-end fw-semibold">{{ number_format($company->recent_items_count) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="empty-state">表示できる新着はありません。</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="surface p-3">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h5 mb-0">最近の新着</h2>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('items.index') }}">
                        <i class="bi bi-list-ul me-1" aria-hidden="true"></i>一覧
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>状態</th>
                            <th>検知日時</th>
                            <th>会社</th>
                            <th>タイトル</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($recentItems as $item)
                            <tr>
                                <td>
                                    <span class="badge {{ $item->reads->isEmpty() ? 'badge-unread' : 'badge-read' }}">
                                        {{ $item->reads->isEmpty() ? '未読' : '既読' }}
                                    </span>
                                </td>
                                <td class="text-nowrap">{{ $item->detected_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ $item->company?->name }}</td>
                                <td class="table-title">
                                    <a href="{{ route('items.show', ['item' => $item, 'return_to' => request()->fullUrl()]) }}">{{ $item->title }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty-state">表示できる新着はありません。</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
@endsection
