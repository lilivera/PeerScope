<?php

namespace App\Http\Controllers;

use App\Models\CollectedItem;
use App\Models\Company;
use App\Models\ItemRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return view('items.show', compact('item'));
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
}
