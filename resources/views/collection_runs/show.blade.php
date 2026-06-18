@extends('layouts.app')

@section('title', '収集ログ詳細 - PeerScope')
@section('page_title', '収集ログ詳細')

@section('content')
    @if($run->status === 'running')
        <div class="alert alert-primary d-flex align-items-center gap-2" role="alert">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span>収集処理を実行中です。進捗は自動更新されます。</span>
        </div>
    @endif

    <div class="mb-3">
        <a class="btn btn-outline-secondary" href="{{ route('collection-runs.index') }}">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>戻る
        </a>
    </div>

    <section class="surface p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="meta-label">実行開始日時</div>
                <div>{{ $run->started_at?->format('Y-m-d H:i:s') }}</div>
            </div>
            <div class="col-md-3">
                <div class="meta-label">実行終了日時</div>
                <div>{{ $run->finished_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
            </div>
            <div class="col-md-2">
                <div class="meta-label">ステータス</div>
                <div>
                    <span class="badge {{ $run->statusBadgeClass() }}">
                        @if($run->status === 'running')
                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        @endif
                        {{ $run->statusLabel() }}
                    </span>
                </div>
            </div>
            <div class="col-md-2">
                <div class="meta-label">新規</div>
                <div>{{ number_format($run->created_count) }}</div>
            </div>
            <div class="col-md-2">
                <div class="meta-label">更新</div>
                <div>{{ number_format($run->updated_count) }}</div>
            </div>
            <div class="col-md-2">
                <div class="meta-label">エラー</div>
                <div>{{ number_format($run->error_count) }}</div>
            </div>
            <div class="col-12">
                <div class="meta-label">対象収集先</div>
                <div>{{ $run->targetSummary(10) }}</div>
            </div>
            <div class="col-12">
                <div class="meta-label">メッセージ</div>
                <div>{{ $run->message ?? '-' }}</div>
            </div>
            <div class="col-12">
                <div class="meta-label">最終更新日時</div>
                <div>{{ $run->updated_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
            </div>
        </div>
    </section>

    <section class="surface p-3 mb-4">
        <h2 class="h5 mb-3">対象収集先</h2>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>会社</th>
                    <th>収集元</th>
                    <th>URL</th>
                </tr>
                </thead>
                <tbody>
                @forelse($run->targetSources as $targetSource)
                    <tr>
                        <td>{{ $targetSource->company_name ?? '-' }}</td>
                        <td>{{ $targetSource->source_name }}</td>
                        <td>
                            <a href="{{ $targetSource->source_url }}" target="_blank" rel="noopener noreferrer">
                                {{ $targetSource->source_url }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty-state">対象収集先は記録されていません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="surface p-3">
        <h2 class="h5 mb-3">エラー</h2>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>発生日時</th>
                    <th>会社</th>
                    <th>収集元</th>
                    <th>種別</th>
                    <th>内容</th>
                </tr>
                </thead>
                <tbody>
                @forelse($run->errors as $error)
                    <tr>
                        <td class="text-nowrap">{{ $error->occurred_at?->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $error->watchSource?->company?->name ?? '-' }}</td>
                        <td>{{ $error->watchSource?->source_name ?? '-' }}</td>
                        <td>{{ $error->error_type }}</td>
                        <td>{{ $error->error_message }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-state">エラーはありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

@if($run->status === 'running')
    @push('scripts')
        <script>
            window.setTimeout(() => window.location.reload(), 5000);
        </script>
    @endpush
@endif
