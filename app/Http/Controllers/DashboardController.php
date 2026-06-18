<?php

namespace App\Http\Controllers;

use App\Models\CollectedItem;
use App\Models\CollectionError;
use App\Models\CollectionRun;
use App\Models\Company;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        // ダッシュボードは即時性を優先し、カードと一覧に必要な集計だけを個別に取得する。
        return view('dashboard', [
            'todayCount' => CollectedItem::query()->whereDate('detected_at', today())->count(),
            'unreadCount' => CollectedItem::query()
                ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))
                ->count(),
            'latestRun' => CollectionRun::query()
                ->where('target_count', '>', 0)
                ->latest('finished_at')
                ->first(),
            'errorCount' => CollectionError::query()->where('occurred_at', '>=', now()->subDay())->count(),
            'companyCounts' => Company::query()
                // 直近7日の件数だけをwithCountで付け、会社別ランキングに使う。
                ->withCount(['collectedItems as recent_items_count' => fn ($query) => $query->where('detected_at', '>=', now()->subDays(7))])
                ->orderByDesc('recent_items_count')
                ->limit(8)
                ->get(),
            'recentItems' => CollectedItem::query()
                // 既読状態はログインユーザー分だけを読み込み、一覧の表示判定を軽くする。
                ->with(['company', 'watchSource', 'reads' => fn ($query) => $query->where('user_id', $user->id)])
                ->latest('detected_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
