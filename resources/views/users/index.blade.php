@extends('layouts.app')

@section('title', 'ユーザー管理 - PeerScope')
@section('page_title', 'ユーザー管理')

@section('content')
    <div class="surface p-3 mb-3">
        <form class="row g-2 align-items-end" method="post" action="{{ route('users.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="col-lg-7">
                <label class="form-label" for="csv_file">CSVファイル</label>
                <input class="form-control" id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
            </div>
            <div class="col-lg-auto">
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>CSV取込
                </button>
            </div>
            <div class="col-12 text-muted small">
                CSV列: action, login_id, name, email, password, role / action: 登録, 削除 / role: user, admin（省略時はuser）
            </div>
        </form>
    </div>

    <div class="surface p-3 mb-3">
        <form method="post" action="{{ route('users.import-setting.update') }}">
            @csrf
            <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" id="is_enabled" name="is_enabled" type="checkbox" value="1" @checked(old('is_enabled', $importSetting->is_enabled))>
                    <label class="form-check-label fw-semibold" for="is_enabled">ユーザーCSV取込ジョブ</label>
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>設定保存
                </button>
            </div>

            <div class="row g-3">
                <div class="col-lg-3">
                    <label class="form-label" for="scheduled_time">実行時刻</label>
                    <input class="form-control" id="scheduled_time" name="scheduled_time" type="time" value="{{ old('scheduled_time', $importSetting->scheduledTime()) }}" required>
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="import_directory">取込フォルダ</label>
                    <div class="input-group">
                        <input class="form-control" id="import_directory" name="import_directory" value="{{ old('import_directory', $importSetting->import_directory) }}">
                        <button class="btn btn-outline-secondary js-directory-picker" type="button" data-directory-picker-target="#import_directory">
                            <i class="bi bi-folder2-open" aria-hidden="true"></i>選択
                        </button>
                    </div>
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="processed_directory">処理済みフォルダ</label>
                    <div class="input-group">
                        <input class="form-control" id="processed_directory" name="processed_directory" value="{{ old('processed_directory', $importSetting->processed_directory) }}">
                        <button class="btn btn-outline-secondary js-directory-picker" type="button" data-directory-picker-target="#processed_directory">
                            <i class="bi bi-folder2-open" aria-hidden="true"></i>選択
                        </button>
                    </div>
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="failed_directory">失敗フォルダ</label>
                    <div class="input-group">
                        <input class="form-control" id="failed_directory" name="failed_directory" value="{{ old('failed_directory', $importSetting->failed_directory) }}">
                        <button class="btn btn-outline-secondary js-directory-picker" type="button" data-directory-picker-target="#failed_directory">
                            <i class="bi bi-folder2-open" aria-hidden="true"></i>選択
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div class="d-flex align-items-center justify-content-between gap-3 mt-3">
            <div class="text-muted small">
                @if($importSetting->last_run_at)
                    最終実行: {{ $importSetting->last_run_at->format('Y-m-d H:i') }} / {{ $importSetting->last_result }}
                @else
                    最終実行: -
                @endif
            </div>
            <form method="post" action="{{ route('users.import-folder.run') }}">
                @csrf
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-play-fill me-1" aria-hidden="true"></i>フォルダ取込実行
                </button>
            </form>
        </div>
    </div>

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
                            <span class="badge {{ $user->isAdmin() ? 'badge-read' : 'badge-soft' }}">
                                {{ $user->isAdmin() ? '管理者' : '一般ユーザー' }}
                            </span>
                        </td>
                        <td class="text-nowrap">{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-end">
                            @unless($user->isSystemAdmin())
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('users.edit', $user) }}" title="編集">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </a>
                            @endunless
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

    <div class="modal fade" id="directoryPickerModal" tabindex="-1" aria-labelledby="directoryPickerLabel" aria-hidden="true" data-directory-picker-url="{{ route('users.import-directories') }}">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-6" id="directoryPickerLabel">フォルダ選択</h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <span class="text-muted small" data-directory-picker-current>storage/app</span>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-directory-picker-parent>
                            <i class="bi bi-arrow-up" aria-hidden="true"></i>上へ
                        </button>
                    </div>
                    <div class="list-group" data-directory-picker-list></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">キャンセル</button>
                    <button class="btn btn-primary" type="button" data-directory-picker-select>
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>このフォルダを選択
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
