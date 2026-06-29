@extends('layouts.app')

@section('title', $item->title.' - PeerScope')
@section('page_title', '詳細ビュワー')

@section('content')
    <div class="mb-3 d-flex gap-2">
        <a class="btn btn-outline-secondary" href="{{ $returnTo }}">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>戻る
        </a>
        <a class="btn btn-primary" href="{{ $item->url }}" target="_blank" rel="noopener noreferrer">
            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>元URL
        </a>
        @if($item->hasDownloadedPdf())
            <a class="btn btn-outline-primary" href="{{ route('items.pdf', $item) }}">
                <i class="bi bi-download me-1" aria-hidden="true"></i>PDF
            </a>
        @endif
    </div>

    <article class="surface p-4">
        <div class="d-flex align-items-center gap-2 mb-3">
            <span class="badge badge-read">既読</span>
        </div>

        <h2 class="h3 mb-4">{{ $item->title }}</h2>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="meta-label">会社名</div>
                <div>{{ $item->company?->name }}</div>
            </div>
            <div class="col-md-4">
                <div class="meta-label">掲載日</div>
                <div>{{ $item->published_at?->format('Y-m-d H:i') ?? '-' }}</div>
            </div>
            <div class="col-md-4">
                <div class="meta-label">検知日時</div>
                <div>{{ $item->detected_at?->format('Y-m-d H:i') }}</div>
            </div>
            <div class="col-md-8">
                <div class="meta-label">元URL</div>
                <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer">{{ $item->url }}</a>
            </div>
            @if($item->hasDownloadedPdf())
                <div class="col-md-4">
                    <div class="meta-label">保存済みPDF</div>
                    <a href="{{ route('items.pdf', $item) }}">{{ $item->pdf_original_filename }}</a>
                    @if($item->pdf_file_size)
                        <span class="text-muted small">({{ number_format($item->pdf_file_size / 1024, 1) }} KB)</span>
                    @endif
                </div>
                <div class="col-md-4">
                    <div class="meta-label">PDF取得日時</div>
                    <div>{{ $item->pdf_downloaded_at?->format('Y-m-d H:i') }}</div>
                </div>
            @endif
        </div>

        <div class="mb-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="meta-label">要約</div>
                <form method="post" action="{{ route('items.ai-summary', $item) }}" data-ai-summary-form>
                    @csrf
                    <input type="hidden" name="return_to" value="{{ $returnTo }}">
                    <button class="btn btn-sm btn-outline-primary" type="submit" data-ai-summary-button>
                        <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true" data-ai-summary-spinner></span>
                        <i class="bi bi-stars me-1" aria-hidden="true" data-ai-summary-icon></i>
                        <span data-ai-summary-label>{{ $item->hasAiSummary() ? 'AI要約を再生成' : 'AI要約を生成' }}</span>
                    </button>
                </form>
            </div>
            <div class="alert alert-info py-2 px-3 small d-none" role="status" aria-live="polite" data-ai-summary-status>
                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>AI要約を生成しています。完了までこの画面のままお待ちください。
            </div>

            @if($item->hasAiSummary())
                <div class="text-muted small mb-2">
                    AI要約: {{ $item->ai_summary_model ?? '-' }}
                    @if($item->ai_summary_generated_at)
                        / {{ $item->ai_summary_generated_at->format('Y-m-d H:i') }}
                    @endif
                </div>
            @endif

            <div class="border rounded p-3 bg-light" style="white-space: pre-wrap;">{{ $item->displaySummary() ?: '-' }}</div>

            @if($item->hasAiSummary() && $item->summary)
                <details class="mt-2">
                    <summary class="text-muted small">収集時の要約</summary>
                    <div class="border rounded p-3 mt-2" style="white-space: pre-wrap;">{{ $item->summary }}</div>
                </details>
            @endif
        </div>

        @if($item->body_text)
            <div>
                <div class="meta-label mb-2">本文テキスト</div>
                <div class="border rounded p-3 bg-light" style="white-space: pre-wrap;">{{ $item->body_text }}</div>
            </div>
        @endif
    </article>
@endsection
