<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserImportSetting;
use App\Services\UserCsvImportService;
use App\Support\BackgroundArtisanRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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
            'importSetting' => UserImportSetting::current(),
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
        $this->ensureManagedUser($user);

        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $this->ensureManagedUser($user);

        $user->update($this->validatedData($request, $user));

        return redirect()->route('users.index')->with('status', 'ユーザーを更新しました。');
    }

    public function import(Request $request, UserCsvImportService $importer)
    {
        $validated = $request->validate([
            'csv_file' => ['required', 'file', 'max:2048'],
        ]);

        $result = $importer->import($validated['csv_file']);

        return redirect()
            ->route('users.index')
            ->with('status', sprintf('CSV処理が完了しました。登録 %d件、削除 %d件', $result['created'], $result['deleted']));
    }

    public function updateImportSetting(Request $request)
    {
        $data = $request->validate([
            'scheduled_time' => ['required', 'date_format:H:i'],
            'import_directory' => ['nullable', 'string', 'max:1024'],
            'processed_directory' => ['nullable', 'string', 'max:1024'],
            'failed_directory' => ['nullable', 'string', 'max:1024'],
        ]);

        UserImportSetting::current()->update([
            'is_enabled' => $request->boolean('is_enabled'),
            'scheduled_time' => $data['scheduled_time'],
            'import_directory' => $this->directoryOrDefault($data['import_directory'] ?? null, UserImportSetting::defaultImportDirectory()),
            'processed_directory' => $this->directoryOrDefault($data['processed_directory'] ?? null, UserImportSetting::defaultProcessedDirectory()),
            'failed_directory' => $this->directoryOrDefault($data['failed_directory'] ?? null, UserImportSetting::defaultFailedDirectory()),
        ]);

        return redirect()->route('users.index')->with('status', 'ユーザーCSV取込ジョブ設定を保存しました。');
    }

    public function runFolderImport(BackgroundArtisanRunner $backgroundRunner)
    {
        if (app()->environment('testing')) {
            Artisan::call('peerscope:import-users-folder', ['--force' => true]);

            return redirect()
                ->route('users.index')
                ->with('status', trim(Artisan::output()) ?: 'ユーザーCSVフォルダ取込を実行しました。');
        }

        $started = $backgroundRunner->run('peerscope:import-users-folder', ['--force']);

        if (! $started) {
            Artisan::call('peerscope:import-users-folder', ['--force' => true]);

            return redirect()
                ->route('users.index')
                ->with('status', trim(Artisan::output()) ?: 'ユーザーCSVフォルダ取込を実行しました。');
        }

        return redirect()->route('users.index')->with('status', 'ユーザーCSVフォルダ取込ジョブを起動しました。');
    }

    public function importDirectories(Request $request)
    {
        $this->ensureDefaultImportDirectories();

        $basePath = storage_path('app');
        $currentPath = $this->guardImportDirectoryPath(
            $this->resolveImportDirectoryRequest((string) $request->query('path', ''), $basePath),
            $basePath,
        );
        $currentRelativePath = $this->relativeStorageDirectory($currentPath, $basePath);
        $parentPath = dirname($currentPath);
        $parentRelativePath = $this->isPathInside($parentPath, $basePath)
            ? $this->relativeStorageDirectory($parentPath, $basePath)
            : null;

        $directories = collect(File::directories($currentPath))
            ->sortBy(fn (string $directory): string => basename($directory))
            ->map(fn (string $directory): array => [
                'name' => basename($directory),
                'path' => $this->relativeStorageDirectory($directory, $basePath),
            ])
            ->values();

        return response()->json([
            'current' => [
                'path' => $currentRelativePath,
                'label' => $currentRelativePath !== '' ? $currentRelativePath : 'storage/app',
            ],
            'parent' => $parentRelativePath,
            'directories' => $directories,
        ]);
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

    private function ensureManagedUser(User $user): void
    {
        abort_if($user->isSystemAdmin(), 403, 'システム管理者ユーザーはユーザー管理の対象外です。');
    }

    private function directoryOrDefault(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    private function ensureDefaultImportDirectories(): void
    {
        foreach ([
            UserImportSetting::defaultImportDirectory(),
            UserImportSetting::defaultProcessedDirectory(),
            UserImportSetting::defaultFailedDirectory(),
        ] as $directory) {
            File::ensureDirectoryExists(storage_path('app/'.$directory));
        }
    }

    private function resolveImportDirectoryRequest(string $path, string $basePath): string
    {
        $path = trim($path);

        if ($path === '') {
            return $basePath;
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $basePath.DIRECTORY_SEPARATOR.trim($path, '\\/');
    }

    private function guardImportDirectoryPath(string $path, string $basePath): string
    {
        $realBasePath = realpath($basePath) ?: $basePath;
        $realPath = realpath($path);

        if (! $realPath || ! is_dir($realPath) || ! $this->isPathInside($realPath, $realBasePath)) {
            return $realBasePath;
        }

        return $realPath;
    }

    private function relativeStorageDirectory(string $path, string $basePath): string
    {
        $path = str_replace('\\', '/', $path);
        $basePath = rtrim(str_replace('\\', '/', realpath($basePath) ?: $basePath), '/');
        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        $comparisonBasePath = PHP_OS_FAMILY === 'Windows' ? strtolower($basePath) : $basePath;

        if ($comparisonPath === $comparisonBasePath) {
            return '';
        }

        return ltrim(substr($path, strlen($basePath)), '/');
    }

    private function isPathInside(string $path, string $basePath): bool
    {
        $path = rtrim($this->normalizePath(realpath($path) ?: $path), '/');
        $basePath = rtrim($this->normalizePath(realpath($basePath) ?: $basePath), '/');

        return $path === $basePath || str_starts_with($path.'/', $basePath.'/');
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
