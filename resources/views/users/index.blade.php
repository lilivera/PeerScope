@extends('layouts.app')

@section('title', 'ユーザー管理 - PeerScope')
@section('page_title', 'ユーザー管理')

@section('content')
    <div class="d-flex justify-content-end mb-3">
        <a class="btn btn-primary" href="{{ route('users.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>登録
        </a>
    </div>

    <section class="surface p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>名前</th>
                    <th>メールアドレス</th>
                    <th>権限</th>
                    <th>作成日時</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($users as $user)
                    <tr>
                        <td class="fw-semibold">{{ $user->login_id }}</td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <span class="badge {{ $user->role === 'admin' ? 'badge-read' : 'badge-soft' }}">
                                {{ $user->role === 'admin' ? '管理者' : '一般ユーザー' }}
                            </span>
                        </td>
                        <td class="text-nowrap">{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('users.edit', $user) }}" title="編集">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-state">ユーザーはまだ登録されていません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $users->links() }}
    </section>
@endsection
