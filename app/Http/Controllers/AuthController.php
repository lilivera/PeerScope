<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        // PeerScopeではメールアドレスではなく、ユーザー登録時に決めたIDでログインする。
        $data = $request->validate([
            'login_id' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $credentials = [
            'login_id' => trim($data['login_id']),
            'password' => $data['password'],
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'login_id' => 'IDまたはパスワードが正しくありません。',
            ]);
        }

        // 認証後にセッションIDを作り直し、ログイン前のセッション固定化を防ぐ。
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function logout(Request $request)
    {
        Auth::logout();

        // ログアウト後に古いセッション情報を残さない。
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
