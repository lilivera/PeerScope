<?php

namespace App\Console\Commands;

use App\Models\UserImportSetting;
use App\Services\UserCsvImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportUsersFromFolder extends Command
{
    protected $signature = 'peerscope:import-users-folder {--force : 設定や実行時刻を無視して実行する}';

    protected $description = '設定フォルダに配置されたユーザーCSVを取り込む';

    public function handle(UserCsvImportService $importer): int
    {
        $setting = UserImportSetting::current();

        if (! $setting->is_enabled && ! $this->option('force')) {
            $this->info('ユーザーCSVフォルダ取込は無効です。');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->isDue($setting)) {
            $message = 'ユーザーCSVフォルダ取込は指定時刻ではありません。実行時刻: '.$setting->scheduledTime();
            $this->info($message);

            return self::SUCCESS;
        }

        $importPath = $setting->importPath();
        $processedPath = $setting->processedPath();
        $failedPath = $setting->failedPath();

        $this->ensureDirectories([$importPath, $processedPath, $failedPath]);

        $files = collect(File::files($importPath))
            ->filter(fn ($file): bool => strtolower($file->getExtension()) === 'csv')
            ->sortBy(fn ($file): string => $file->getFilename())
            ->values();

        if ($files->isEmpty()) {
            $message = '処理対象CSVはありません。';
            $this->updateLastResult($setting, $message);
            $this->info($message);

            return self::SUCCESS;
        }

        $created = 0;
        $deleted = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                $result = $importer->importPath($file->getPathname());
                $created += $result['created'];
                $deleted += $result['deleted'];
                $succeeded++;
                $this->moveFile($file->getPathname(), $processedPath);
                $this->line($file->getFilename().' を取り込みました。');
            } catch (Throwable $exception) {
                $failed++;
                $messages = $this->exceptionMessages($exception);
                $movedPath = $this->moveFile($file->getPathname(), $failedPath);
                File::put($movedPath.'.error.txt', implode(PHP_EOL, $messages));
                $this->error($file->getFilename().' の取り込みに失敗しました。');
            }
        }

        $message = sprintf(
            'CSV %d件処理、成功 %d件、失敗 %d件、登録 %d件、削除 %d件',
            $files->count(),
            $succeeded,
            $failed,
            $created,
            $deleted,
        );

        $this->updateLastResult($setting, $message);
        $this->info($message);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isDue(UserImportSetting $setting): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', $setting->scheduledTime()));
        $scheduledAt = now()->copy()->setTime($hour, $minute);

        if (now()->lt($scheduledAt)) {
            return false;
        }

        return $setting->last_run_at === null || $setting->last_run_at->lt($scheduledAt);
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function ensureDirectories(array $paths): void
    {
        foreach ($paths as $path) {
            if (! File::isDirectory($path)) {
                File::makeDirectory($path, 0755, true);
            }
        }
    }

    private function moveFile(string $sourcePath, string $targetDirectory): string
    {
        $targetPath = $this->uniqueTargetPath($targetDirectory, basename($sourcePath));
        File::move($sourcePath, $targetPath);

        return $targetPath;
    }

    private function uniqueTargetPath(string $directory, string $filename): string
    {
        $targetPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.now()->format('Ymd_His_').$filename;
        $info = pathinfo($targetPath);
        $counter = 1;

        while (File::exists($targetPath)) {
            $targetPath = $info['dirname'].DIRECTORY_SEPARATOR.$info['filename'].'_'.$counter.'.'.($info['extension'] ?? 'csv');
            $counter++;
        }

        return $targetPath;
    }

    /**
     * @return array<int, string>
     */
    private function exceptionMessages(Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())
                ->flatten()
                ->map(fn (mixed $message): string => (string) $message)
                ->values()
                ->all();
        }

        return [$exception->getMessage()];
    }

    private function updateLastResult(UserImportSetting $setting, string $message): void
    {
        $setting->forceFill([
            'last_run_at' => now(),
            'last_result' => $message,
        ])->save();
    }
}
