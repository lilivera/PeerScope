<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserCsvImportService
{
    /**
     * CSVの全行を検証してから、一般ユーザーの登録・削除をまとめて反映する。
     *
     * @return array{created:int, deleted:int}
     */
    public function import(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            throw ValidationException::withMessages(['csv_file' => 'CSVファイルを読み込めませんでした。']);
        }

        return $this->importContent($content);
    }

    /**
     * フォルダ配置されたCSVを、画面アップロードと同じ規則で取り込む。
     *
     * @return array{created:int, deleted:int}
     */
    public function importPath(string $path): array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw ValidationException::withMessages(['csv_file' => 'CSVファイルを読み込めませんでした。']);
        }

        return $this->importContent($content);
    }

    /**
     * @return array{created:int, deleted:int}
     */
    private function importContent(string $content): array
    {
        $rows = $this->readRows($content);
        $preparedRows = $this->prepareRows($rows);
        $errors = $this->validateRows($preparedRows);

        if ($errors !== []) {
            throw ValidationException::withMessages(['csv_file' => $errors]);
        }

        return DB::transaction(function () use ($preparedRows): array {
            $created = 0;
            $deleted = 0;

            foreach ($preparedRows as $row) {
                if ($row['action'] === 'create') {
                    User::create([
                        'name' => $row['name'],
                        'login_id' => $row['login_id'],
                        'email' => $row['email'],
                        'password' => Hash::make($row['password']),
                        'role' => $this->normalizeRole((string) $row['role']) ?? 'user',
                    ]);
                    $created++;
                }

                if ($row['action'] === 'delete') {
                    User::query()
                        ->where('login_id', $row['login_id'])
                        ->firstOrFail()
                        ->delete();
                    $deleted++;
                }
            }

            return compact('created', 'deleted');
        });
    }

    /**
     * ヘッダー付きCSVを、行番号つきの連想配列に変換する。
     *
     * @return array<int, array{line:int, values:array<string, string>}>
     */
    private function readRows(string $content): array
    {
        $content = $this->normalizeEncoding($content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (trim($content) === '') {
            throw ValidationException::withMessages(['csv_file' => 'CSVファイルにデータがありません。']);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $headers = fgetcsv($stream);

        if ($headers === false) {
            throw ValidationException::withMessages(['csv_file' => 'CSVヘッダーを読み込めませんでした。']);
        }

        $headers = $this->normalizeHeaders($headers);
        $this->ensureRequiredHeaders($headers);

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($stream)) !== false) {
            $line++;

            if ($this->isBlankRow($values)) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }

            $rows[] = [
                'line' => $line,
                'values' => $row,
            ];
        }

        fclose($stream);

        if ($rows === []) {
            throw ValidationException::withMessages(['csv_file' => 'CSVファイルに処理対象行がありません。']);
        }

        return $rows;
    }

    private function normalizeEncoding(string $content): string
    {
        if (function_exists('mb_check_encoding') && ! mb_check_encoding($content, 'UTF-8')) {
            return mb_convert_encoding($content, 'UTF-8', 'SJIS-win,UTF-8,EUC-JP,JIS');
        }

        return $content;
    }

    /**
     * @param  array<int, string|null>  $headers
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function (?string $header): string {
            $header = trim((string) $header);
            $lower = strtolower($header);

            return match ($lower) {
                'action', '操作', '処理' => 'action',
                'login_id', 'login id', 'id', 'ログインid', 'ユーザーid', 'ユーザid' => 'login_id',
                'name', '名前', '氏名' => 'name',
                'email', 'mail', 'メール', 'メールアドレス' => 'email',
                'password', 'pass', 'パスワード' => 'password',
                'role', '権限' => 'role',
                default => $lower,
            };
        }, $headers);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function ensureRequiredHeaders(array $headers): void
    {
        $missing = array_values(array_diff(['action', 'login_id', 'name', 'email', 'password'], $headers));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'csv_file' => 'CSVヘッダーに必要な列がありません: '.implode(', ', $missing),
            ]);
        }
    }

    /**
     * @param  array<int, string|null>  $values
     */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{line:int, values:array<string, string>}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function prepareRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $values = $row['values'];

            return [
                'line' => $row['line'],
                'action' => $this->normalizeAction($values['action'] ?? ''),
                'raw_action' => $values['action'] ?? '',
                'login_id' => trim($values['login_id'] ?? ''),
                'name' => trim($values['name'] ?? ''),
                'email' => trim($values['email'] ?? ''),
                'password' => (string) ($values['password'] ?? ''),
                'role' => trim($values['role'] ?? ''),
            ];
        }, $rows);
    }

    private function normalizeAction(string $action): ?string
    {
        $action = strtolower(trim($action));

        return match ($action) {
            'create', 'add', 'register', '登録', '追加' => 'create',
            'delete', 'remove', '削除' => 'delete',
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function validateRows(array $rows): array
    {
        $errors = [];
        $seenLoginIds = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $loginId = $row['login_id'];
            $email = $row['email'];

            if ($row['action'] === null) {
                $errors[] = "{$line}行目: action は 登録 または 削除 を指定してください。";
            }

            if (! $this->isValidLoginId($loginId)) {
                $errors[] = "{$line}行目: IDには半角英数字、ドット、アンダースコア、ハイフンのみ使用できます。";
            } elseif (isset($seenLoginIds[strtolower($loginId)])) {
                $errors[] = "{$line}行目: 同じIDがCSV内に複数あります。";
            } else {
                $seenLoginIds[strtolower($loginId)] = true;
            }

            if ($row['action'] === 'create') {
                $this->validateCreateRow($row, $errors, $seenEmails);
            }

            if ($row['action'] === 'delete') {
                $this->validateDeleteRow($row, $errors);
            }

            if ($email !== '') {
                $seenEmails[strtolower($email)] = true;
            }
        }

        return $errors;
    }

    private function isValidLoginId(string $loginId): bool
    {
        return $loginId !== ''
            && strlen($loginId) <= 255
            && (bool) preg_match('/^[A-Za-z0-9._-]+$/', $loginId);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $errors
     * @param  array<string, true>  $seenEmails
     */
    private function validateCreateRow(array $row, array &$errors, array $seenEmails): void
    {
        $line = $row['line'];

        if ($this->isSystemAdminLoginId($row['login_id'])) {
            $errors[] = "{$line}行目: IDがadminのユーザーはCSV処理の対象外です。";
        }

        if ($this->normalizeRole((string) $row['role']) === null) {
            $errors[] = "{$line}行目: 権限は user または admin を指定してください。";
        }

        if ($row['name'] === '' || mb_strlen($row['name']) > 255) {
            $errors[] = "{$line}行目: 名前は1文字以上255文字以内で指定してください。";
        }

        if (! filter_var($row['email'], FILTER_VALIDATE_EMAIL) || strlen($row['email']) > 255) {
            $errors[] = "{$line}行目: メールアドレスの形式が正しくありません。";
        } elseif (isset($seenEmails[strtolower($row['email'])])) {
            $errors[] = "{$line}行目: 同じメールアドレスがCSV内に複数あります。";
        }

        if (strlen($row['password']) < 8) {
            $errors[] = "{$line}行目: パスワードは8文字以上で指定してください。";
        }

        $existingByLoginId = User::query()->where('login_id', $row['login_id'])->first();

        if ($existingByLoginId?->isSystemAdmin()) {
            $errors[] = "{$line}行目: IDがadminのユーザーはCSV処理の対象外です。";
        } elseif ($existingByLoginId) {
            $errors[] = "{$line}行目: IDは既に登録されています。";
        }

        $existingByEmail = User::query()->where('email', $row['email'])->first();

        if ($existingByEmail?->isSystemAdmin()) {
            $errors[] = "{$line}行目: IDがadminのユーザーのメールアドレスはCSV処理の対象外です。";
        } elseif ($existingByEmail) {
            $errors[] = "{$line}行目: メールアドレスは既に登録されています。";
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $errors
     */
    private function validateDeleteRow(array $row, array &$errors): void
    {
        $user = User::query()->where('login_id', $row['login_id'])->first();
        $line = $row['line'];

        if (! $user) {
            $errors[] = "{$line}行目: 削除対象のユーザーが見つかりません。";

            return;
        }

        if ($user->isSystemAdmin()) {
            $errors[] = "{$line}行目: IDがadminのユーザーはCSV処理の対象外です。";
        }
    }

    private function normalizeRole(string $role): ?string
    {
        $role = strtolower(trim($role));

        return match ($role) {
            '', 'user', '一般ユーザー' => 'user',
            'admin', '管理者' => 'admin',
            default => null,
        };
    }

    private function isSystemAdminLoginId(string $loginId): bool
    {
        return strtolower(trim($loginId)) === 'admin';
    }
}
