@extends('layouts.app')

@section('title', '新着一覧 - PeerScope')
@section('page_title', '新着一覧')

@section('content')
    <section class="surface p-3 mb-4">
        <form method="get" action="{{ route('items.index') }}" class="row g-3 align-items-end">
            <div class="col-md-4 col-xl-3">
                <label class="form-label" for="q">キーワード</label>
                <input class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="col-md-4 col-xl-2">
                <label class="form-label" for="company_id">会社</label>
                <select class="form-select" id="company_id" name="company_id">
                    <option value="">すべて</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}" @selected(($filters['company_id'] ?? '') == $company->id)>{{ $company->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 col-xl-2">
                <label class="form-label" for="read_state">既読状態</label>
                <select class="form-select" id="read_state" name="read_state">
                    <option value="">すべて</option>
                    <option value="unread" @selected(($filters['read_state'] ?? '') === 'unread')>未読のみ</option>
                    <option value="read" @selected(($filters['read_state'] ?? '') === 'read')>既読のみ</option>
                </select>
            </div>
            <div class="col-md-4 col-xl-3">
                <label class="form-label">掲載日</label>
                <div class="input-group">
                    <input class="form-control" name="published_from" type="date" value="{{ $filters['published_from'] ?? '' }}">
                    <input class="form-control" name="published_to" type="date" value="{{ $filters['published_to'] ?? '' }}">
                </div>
            </div>
            <div class="col-md-4 col-xl-3">
                <label class="form-label">検知日</label>
                <div class="input-group">
                    <input class="form-control" name="detected_from" type="date" value="{{ $filters['detected_from'] ?? '' }}">
                    <input class="form-control" name="detected_to" type="date" value="{{ $filters['detected_to'] ?? '' }}">
                </div>
            </div>
            <div class="col-md-4 col-xl-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-search me-1" aria-hidden="true"></i>検索
                </button>
                <a class="btn btn-outline-secondary" href="{{ route('items.index') }}">クリア</a>
            </div>
        </form>
    </section>

    <section class="surface p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle js-sortable-table">
                <thead>
                <tr>
                    <th>
                        <button class="table-sort-button" type="button" data-sort-column="0" data-sort-type="number">
                            状態 <span class="sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th>
                        <button class="table-sort-button" type="button" data-sort-column="1" data-sort-type="number" data-sort-default="desc">
                            検知日時 <span class="sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th>
                        <button class="table-sort-button" type="button" data-sort-column="2" data-sort-type="number" data-sort-default="desc">
                            掲載日 <span class="sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th>
                        <button class="table-sort-button" type="button" data-sort-column="3">
                            会社名 <span class="sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th>
                        <button class="table-sort-button" type="button" data-sort-column="4">
                            タイトル <span class="sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th>
                        <button class="table-sort-button" type="button" data-sort-column="5">
                            元URL <span class="sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                </tr>
                </thead>
                <tbody>
                @forelse($items as $item)
                    <tr>
                        <td data-sort-value="{{ $item->reads->isEmpty() ? 0 : 1 }}">
                            <span class="badge {{ $item->reads->isEmpty() ? 'badge-unread' : 'badge-read' }}">
                                {{ $item->reads->isEmpty() ? '未読' : '既読' }}
                            </span>
                        </td>
                        <td class="text-nowrap" data-sort-value="{{ $item->detected_at?->timestamp ?? 0 }}">{{ $item->detected_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-nowrap" data-sort-value="{{ $item->published_at?->timestamp ?? 0 }}">{{ $item->published_at?->format('Y-m-d') ?? '-' }}</td>
                        <td data-sort-value="{{ $item->company?->name ?? '' }}">{{ $item->company?->name }}</td>
                        <td class="table-title" data-sort-value="{{ $item->title }}"><a href="{{ route('items.show', ['item' => $item, 'return_to' => request()->fullUrl()]) }}">{{ $item->title }}</a></td>
                        <td data-sort-value="{{ $item->url }}">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" title="元URL">
                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                </a>
                                @if($item->hasDownloadedPdf())
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('items.pdf', $item) }}" title="PDF">
                                        <i class="bi bi-download" aria-hidden="true"></i>
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-state">条件に一致する新着はありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="items-pagination mt-3">
            {{ $items->links('vendor.pagination.peerscope-centered') }}
        </div>
    </section>
@endsection
