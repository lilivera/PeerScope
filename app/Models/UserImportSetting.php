<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserImportSetting extends Model
{
    protected $fillable = [
        'is_enabled',
        'scheduled_time',
        'import_directory',
        'processed_directory',
        'failed_directory',
        'last_run_at',
        'last_result',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $setting = self::query()->first();

        if ($setting) {
            return $setting;
        }

        $setting = new self([
            'is_enabled' => false,
            'scheduled_time' => self::defaultScheduledTime(),
            'import_directory' => self::defaultImportDirectory(),
            'processed_directory' => self::defaultProcessedDirectory(),
            'failed_directory' => self::defaultFailedDirectory(),
        ]);
        $setting->id = 1;
        $setting->save();

        return $setting;
    }

    public static function defaultImportDirectory(): string
    {
        return 'user-import/inbox';
    }

    public static function defaultScheduledTime(): string
    {
        return '09:00';
    }

    public function scheduledTime(): string
    {
        $scheduledTime = trim((string) ($this->scheduled_time ?: self::defaultScheduledTime()));

        if (preg_match('/^(\d{2}:\d{2})/', $scheduledTime, $matches)) {
            return $matches[1];
        }

        return self::defaultScheduledTime();
    }

    public static function defaultProcessedDirectory(): string
    {
        return 'user-import/processed';
    }

    public static function defaultFailedDirectory(): string
    {
        return 'user-import/failed';
    }

    public function importPath(): string
    {
        return $this->resolveDirectory($this->import_directory ?: self::defaultImportDirectory());
    }

    public function processedPath(): string
    {
        return $this->resolveDirectory($this->processed_directory ?: self::defaultProcessedDirectory());
    }

    public function failedPath(): string
    {
        return $this->resolveDirectory($this->failed_directory ?: self::defaultFailedDirectory());
    }

    private function resolveDirectory(string $path): string
    {
        $path = trim($path);

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return storage_path('app/'.trim($path, '\\/'));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
