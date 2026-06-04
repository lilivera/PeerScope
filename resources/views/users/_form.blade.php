<section class="surface p-4">
    <form method="post" action="{{ $action }}">
        @csrf
        @if($method)
            @method($method)
        @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="login_id">ID</label>
                <input class="form-control" id="login_id" name="login_id" value="{{ old('login_id', $user->login_id) }}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="name">名前</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">メールアドレス</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="role">権限</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="user" @selected(old('role', $user->role) === 'user')>一般ユーザー</option>
                    <option value="admin" @selected(old('role', $user->role) === 'admin')>管理者</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password">パスワード</label>
                <input class="form-control" id="password" name="password" type="password" @required(! $user->exists)>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password_confirmation">パスワード確認</label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" @required(! $user->exists)>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ $button }}
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('users.index') }}">戻る</a>
        </div>
    </form>
</section>
