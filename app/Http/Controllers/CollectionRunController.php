<?php

namespace App\Http\Controllers;

use App\Models\CollectionRun;

class CollectionRunController extends Controller
{
    public function index()
    {
        return view('collection_runs.index', [
            'runs' => CollectionRun::query()
                ->withCount('errors')
                ->latest('started_at')
                ->paginate(50),
        ]);
    }

    public function show(CollectionRun $collectionRun)
    {
        // 詳細画面では、エラーが出た収集元と会社名までまとめて表示する。
        $collectionRun->load(['errors.watchSource.company']);

        return view('collection_runs.show', ['run' => $collectionRun]);
    }
}
