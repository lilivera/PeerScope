@extends('layouts.app')

@section('title', '会社管理 - PeerScope')
@section('page_title', '会社管理')

@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a class="btn btn-primary" href="{{ route('companies.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>登録
        </a>
    </div>

    <section class="surface p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>会社名</th>
                    <th>公式URL</th>
                    <th>有効区分</th>
                    <th class="text-end">収集先</th>
                    <th class="text-end">新着</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($companies as $company)
                    <tr>
                        <td class="fw-semibold">{{ $company->name }}</td>
                        <td>
                            @if($company->official_url)
                                <a href="{{ $company->official_url }}" target="_blank" rel="noopener noreferrer">{{ $company->official_url }}</a>
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $company->is_active ? 'badge-read' : 'badge-soft' }}">
                                {{ $company->is_active ? '有効' : '無効' }}
                            </span>
                        </td>
                        <td class="text-end">{{ number_format($company->watch_sources_count) }}</td>
                        <td class="text-end">{{ number_format($company->collected_items_count) }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('companies.edit', $company) }}">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-state">会社はまだ登録されていません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $companies->links() }}
    </section>
@endsection
