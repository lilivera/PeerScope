@extends('layouts.app')

@section('title', 'ログイン - PeerScope')

@section('content')
    <div class="row justify-content-center align-items-center" style="min-height: calc(100vh - 3rem);">
        <div class="col-md-6 col-lg-4">
            <div class="text-center mb-4">
                <i class="bi bi-radar site-icon display-5" aria-hidden="true"></i>
                <h1 class="brand-mark mt-2">PeerScope</h1>
            </div>
            <div class="surface p-4">
                <form method="post" action="{{ route('login') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="login_id">ID</label>
                        <input class="form-control" id="login_id" name="login_id" type="text" value="{{ old('login_id') }}" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">パスワード</label>
                        <input class="form-control" id="password" name="password" type="password" required>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                        <label class="form-check-label" for="remember">ログイン状態を保持</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>ログイン
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
