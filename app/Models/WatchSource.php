<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WatchSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'source_name',
        'source_url',
        'source_type',
        'list_selector',
        'title_selector',
        'url_selector',
        'date_selector',
        'body_selector',
        'crawl_interval_minutes',
        'schedule_type',
        'schedule_time',
        'schedule_weekdays',
        'schedule_month_days',
        'auto_ai_summary',
        'last_crawled_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'crawl_interval_minutes' => 'integer',
            'schedule_weekdays' => 'array',
            'schedule_month_days' => 'array',
            'auto_ai_summary' => 'boolean',
            'last_crawled_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function collectedItems()
    {
        return $this->hasMany(CollectedItem::class);
    }

    public function errors()
    {
        return $this->hasMany(CollectionError::class);
    }

    public function isDue(): bool
    {
        $now = now();

        if ($this->scheduleType() === 'interval') {
            // 未収集の収集先は初回実行対象にする。
            if (! $this->last_crawled_at) {
                return true;
            }

            // 最終収集日時から設定間隔を過ぎていれば、スケジュール収集の対象にする。
            return $this->last_crawled_at->copy()->addMinutes($this->crawl_interval_minutes)->lte($now);
        }

        $scheduledAt = $this->scheduledAtFor($now);

        if (! $scheduledAt || $now->lt($scheduledAt)) {
            return false;
        }

        return $this->last_crawled_at === null || $this->last_crawled_at->lt($scheduledAt);
    }

    public function sourceTypeLabel(): string
    {
        return match ($this->source_type) {
            'auto' => '自動',
            'rss' => 'RSS',
            'html' => 'HTML詳細',
            default => (string) $this->source_type,
        };
    }

    public function scheduleLabel(): string
    {
        $time = $this->scheduleTime();

        return match ($this->scheduleType()) {
            'daily' => '毎日 '.$time,
            'weekly' => '毎週 '.$this->weekdayLabel().' '.$time,
            'monthly' => '毎月 '.$this->monthDayLabel().' '.$time,
            default => number_format($this->crawl_interval_minutes).'分ごと',
        };
    }

    private function scheduleType(): string
    {
        return in_array($this->schedule_type, ['interval', 'daily', 'weekly', 'monthly'], true)
            ? $this->schedule_type
            : 'interval';
    }

    private function scheduleTime(): string
    {
        return preg_match('/^\d{2}:\d{2}$/', (string) $this->schedule_time)
            ? $this->schedule_time
            : '09:00';
    }

    private function scheduledAtFor(Carbon $now): ?Carbon
    {
        if ($this->scheduleType() === 'weekly' && ! in_array($now->dayOfWeek, $this->normalizedWeekdays(), true)) {
            return null;
        }

        if ($this->scheduleType() === 'monthly' && ! in_array($now->day, $this->normalizedMonthDays(), true)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->scheduleTime()));

        return $now->copy()->setTime($hour, $minute);
    }

    /**
     * @return array<int, int>
     */
    private function normalizedWeekdays(): array
    {
        return collect($this->schedule_weekdays ?? [])
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function normalizedMonthDays(): array
    {
        return collect($this->schedule_month_days ?? [])
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 31)
            ->unique()
            ->values()
            ->all();
    }

    private function weekdayLabel(): string
    {
        $labels = ['日', '月', '火', '水', '木', '金', '土'];
        $days = collect($this->normalizedWeekdays())
            ->map(fn (int $day): string => $labels[$day])
            ->all();

        return $days === [] ? '曜日未設定' : implode('・', $days);
    }

    private function monthDayLabel(): string
    {
        $days = collect($this->normalizedMonthDays())
            ->map(fn (int $day): string => $day.'日')
            ->all();

        return $days === [] ? '日付未設定' : implode('・', $days);
    }
}
