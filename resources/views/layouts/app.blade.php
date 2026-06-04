<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'PeerScope')</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('css/peerscope.css') }}" rel="stylesheet">
    @stack('head')
</head>
<body>
<div class="app-shell d-flex">
    @auth
        <aside class="sidebar p-3">
            <div class="sidebar-brand d-flex align-items-center gap-2">
                <i class="bi bi-radar site-icon fs-4" aria-hidden="true"></i>
                <span class="brand-mark fs-4">PeerScope</span>
            </div>
            <nav class="sidebar-nav nav flex-column gap-1">
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                    <i class="bi bi-speedometer2 me-2" aria-hidden="true"></i>ダッシュボード
                </a>
                <a class="nav-link {{ request()->routeIs('items.*') ? 'active' : '' }}" href="{{ route('items.index') }}">
                    <i class="bi bi-newspaper me-2" aria-hidden="true"></i>新着一覧
                </a>
                @if(auth()->user()->isAdmin())
                    <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                        <i class="bi bi-people me-2" aria-hidden="true"></i>ユーザー管理
                    </a>
                    <a class="nav-link {{ request()->routeIs('companies.*') ? 'active' : '' }}" href="{{ route('companies.index') }}">
                        <i class="bi bi-buildings me-2" aria-hidden="true"></i>会社管理
                    </a>
                    <a class="nav-link {{ request()->routeIs('watch-sources.*') ? 'active' : '' }}" href="{{ route('watch-sources.index') }}">
                        <i class="bi bi-broadcast-pin me-2" aria-hidden="true"></i>収集先管理
                    </a>
                    <a class="nav-link {{ request()->routeIs('collection-runs.*') ? 'active' : '' }}" href="{{ route('collection-runs.index') }}">
                        <i class="bi bi-journal-text me-2" aria-hidden="true"></i>収集ログ
                    </a>
                @endif
            </nav>
            <div class="sidebar-user">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <span class="badge text-bg-light border">{{ auth()->user()->isAdmin() ? '管理者' : '一般ユーザー' }}</span>
                </div>
                <div class="sidebar-user-name text-truncate">{{ auth()->user()->name }}</div>
                <form method="post" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit">
                        <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>ログアウト
                    </button>
                </form>
            </div>
        </aside>
    @endauth

    <main class="main-panel flex-grow-1">
        <div class="content-wrap mx-auto p-4">
            @if(session('status'))
                <div class="alert alert-success" role="alert">{{ session('status') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/peerscope.js') }}"></script>
@stack('scripts')
</body>
</html>
