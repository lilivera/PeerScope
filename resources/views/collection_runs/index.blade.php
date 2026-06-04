@extends('layouts.app')

@section('title', '収集ログ - PeerScope')
@section('page_title', '収集ログ')

@section('content')
    <section class="surface p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>実行開始日時</th>
                    <th>実行終了日時</th>
                    <th>ステータス</th>
                    <th class="text-end">対象</th>
                    <th class="text-end">新規</th>
                    <th class="text-end">更新</th>
                    <th class="text-end">エラー</th>
                    <th>メッセージ</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($runs as $run)
                    <tr>
                        <td class="text-nowrap">{{ $run->started_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-nowrap">{{ $run->finished_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td>
                            <span class="badge {{ $run->statusBadgeClass() }}">
                                @if($run->status === 'running')
                                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                                @endif
                                {{ $run->statusLabel() }}
                            </span>
                        </td>
                        <td class="text-end">{{ number_format($run->target_count) }}</td>
                        <td class="text-end">{{ number_format($run->created_count) }}</td>
                        <td class="text-end">{{ number_format($run->updated_count) }}</td>
                        <td class="text-end">{{ number_format($run->error_count) }}</td>
                        <td>{{ $run->message }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('collection-runs.show', $run) }}">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty-state">収集ログはまだありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $runs->links() }}
    </section>
@endsection

@if($runs->contains(fn ($run) => $run->status === 'running'))
    @push('scripts')
        <script>
            window.setTimeout(() => window.location.reload(), 5000);
        </script>
    @endpush
@endif
