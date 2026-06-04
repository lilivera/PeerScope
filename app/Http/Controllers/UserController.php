<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', [
            'users' => User::query()
                ->orderBy('role')
                ->orderBy('login_id')
                ->paginate(50),
        ]);
    }

    public function create()
    {
        return view('users.create', ['user' => new User(['role' => 'user'])]);
    }

    public function store(Request $request)
    {
        User::create($this->validatedData($request));

        return redirect()->route('users.index')->with('status', 'ユーザーを登録しました。');
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $user->update($this->validatedData($request, $user));

        return redirect()->route('users.index')->with('status', 'ユーザーを更新しました。');
    }

    private function validatedData(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'login_id' => [
                // ログインIDは利用者が任意に決めるため、URLや帳票でも扱いやすい半角記号だけに絞る。
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'login_id')->ignore($user),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ], [
            'login_id.regex' => 'IDには半角英数字、ドット、アンダースコア、ハイフンのみ使用できます。',
        ]);

        $data['login_id'] = trim($data['login_id']);

        if (! empty($data['password'])) {
            // パスワードは登録・変更時だけハッシュ化して保存する。
            $data['password'] = Hash::make($data['password']);
        } else {
            // 編集時に空なら既存パスワードを維持する。
            unset($data['password']);
        }

        return $data;
    }
}
