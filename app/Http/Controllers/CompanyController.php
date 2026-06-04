<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index()
    {
        return view('companies.index', [
            'companies' => Company::query()
                // 管理一覧で収集設定数と蓄積済み記事数を並べて確認できるようにする。
                ->withCount(['watchSources', 'collectedItems'])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate(50),
        ]);
    }

    public function create()
    {
        return view('companies.create', ['company' => new Company(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        Company::create($this->validatedData($request));

        return redirect()->route('companies.index')->with('status', '会社を登録しました。');
    }

    public function edit(Company $company)
    {
        return view('companies.edit', compact('company'));
    }

    public function update(Request $request, Company $company)
    {
        $company->update($this->validatedData($request));

        return redirect()->route('companies.index')->with('status', '会社を更新しました。');
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'official_url' => ['nullable', 'url', 'max:2048'],
            'memo' => ['nullable', 'string'],
        ]);

        // チェックボックス未送信時はfalseとして扱う。
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
