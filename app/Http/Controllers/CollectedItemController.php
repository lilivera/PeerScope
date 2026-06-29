<?php

namespace App\Http\Controllers;

use App\Models\CollectedItem;
use App\Models\Company;
use App\Models\ItemRead;
use App\Services\Ai\OllamaSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CollectedItemController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = CollectedItem::query()
            ->with(['company', 'reads' => fn ($readQuery) => $readQuery->where('user_id', $user->id)]);

        // 画面の絞り込み条件はすべて組み合わせ可能にする。
        if ($request->filled('q')) {
            $keyword = $request->string('q')->trim()->toString();

            $query->where(function ($itemQuery) use ($keyword): void {
                // LIKE検索で利用者入力のワイルドカードをそのまま効かせない。
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $keyword).'%';
                $itemQuery
                    ->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('ai_summary', 'like', $like)
                    ->orWhere('body_text', 'like', $like);
            });
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('published_from')) {
            $query->whereDate('published_at', '>=', $request->date('published_from'));
        }

        if ($request->filled('published_to')) {
            $query->whereDate('published_at', '<=', $request->date('published_to'));
        }

        if ($request->filled('detected_from')) {
            $query->whereDate('detected_at', '>=', $request->date('detected_from'));
        }

        if ($request->filled('detected_to')) {
            $query->whereDate('detected_at', '<=', $request->date('detected_to'));
        }

        if ($request->string('read_state')->toString() === 'unread') {
            $query->whereDoesntHave('reads', fn ($readQuery) => $readQuery->where('user_id', $user->id));
        } elseif ($request->string('read_state')->toString() === 'read') {
            $query->whereHas('reads', fn ($readQuery) => $readQuery->where('user_id', $user->id));
        }

        $items = $query
            // 掲載日がある新着を優先し、同日内は検知日時とIDで安定して並べる。
            ->orderByRaw('published_at IS NULL')
            ->orderByDesc('published_at')
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('items.index', [
            'items' => $items,
            'companies' => Company::query()->orderBy('name')->get(),
            'filters' => $request->query(),
        ]);
    }

    public function show(Request $request, CollectedItem $item)
    {
        // 詳細を開いた時点で既読にする。重複登録はDB制約とfirstOrCreateで防ぐ。
        ItemRead::firstOrCreate([
            'user_id' => $request->user()->id,
            'collected_item_id' => $item->id,
        ], [
            'read_at' => now(),
        ]);

        $item->load(['company', 'reads' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        $returnTo = $this->safeReturnTo($request->query('return_to')) ?? route('items.index');

        return view('items.show', compact('item', 'returnTo'));
    }

    public function generateAiSummary(Request $request, CollectedItem $item, OllamaSummaryService $summaryService)
    {
        $item->load('company');
        $returnTo = $this->safeReturnTo($request->input('return_to'));

        try {
            $summary = $summaryService->generate($item);
        } catch (Throwable $exception) {
            return redirect($this->showUrl($item, $returnTo))
                ->withErrors(['ai_summary' => 'AI要約の生成に失敗しました: '.$exception->getMessage()]);
        }

        if ($summary === null) {
            return redirect($this->showUrl($item, $returnTo))
                ->withErrors(['ai_summary' => 'AI要約に使える本文または要約がありません。']);
        }

        $item->forceFill([
            'ai_summary' => $summary,
            'ai_summary_model' => $summaryService->model(),
            'ai_summary_generated_at' => now(),
        ])->save();

        return redirect($this->showUrl($item, $returnTo))
            ->with('status', 'AI要約を生成しました。');
    }

    public function markRead(Request $request, CollectedItem $item)
    {
        // 一覧からの手動既読化も詳細表示と同じ既読レコードを使う。
        ItemRead::firstOrCreate([
            'user_id' => $request->user()->id,
            'collected_item_id' => $item->id,
        ], [
            'read_at' => now(),
        ]);

        return back()->with('status', '既読にしました。');
    }

    public function downloadPdf(CollectedItem $item): StreamedResponse
    {
        // PDFはprivateディスクに保存し、認証済みユーザーだけがこの経路で取得できる。
        abort_unless($item->hasDownloadedPdf(), 404);
        abort_unless(Storage::disk('local')->exists($item->pdf_storage_path), 404);

        return Storage::disk('local')->download(
            $item->pdf_storage_path,
            $item->pdf_original_filename ?: 'document.pdf',
            ['Content-Type' => $item->pdf_mime_type ?: 'application/pdf'],
        );
    }

    private function showUrl(CollectedItem $item, ?string $returnTo): string
    {
        if (! $returnTo) {
            return route('items.show', $item);
        }

        return route('items.show', [
            'item' => $item,
            'return_to' => $returnTo,
        ]);
    }

    private function safeReturnTo(mixed $value): ?string
    {
        $returnTo = trim((string) $value);

        if ($returnTo === '' || Str::startsWith($returnTo, '//')) {
            return null;
        }

        $baseUrl = rtrim(url('/'), '/');

        if (Str::startsWith($returnTo, '/')) {
            $returnTo = $baseUrl.$returnTo;
        }

        if (! Str::startsWith($returnTo, $baseUrl)) {
            return null;
        }

        $path = (string) parse_url($returnTo, PHP_URL_PATH);

        // 戻る先が詳細画面やAI要約POSTだと、要約後に同じ画面へ戻り続けてしまう。
        if (preg_match('#/items/\d+(?:/ai-summary)?/?$#', $path)) {
            return null;
        }

        return $returnTo;
    }
}
