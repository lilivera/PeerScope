<?php

namespace App\Models;

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
        'last_crawled_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'crawl_interval_minutes' => 'integer',
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
        // 未収集の収集先は初回実行対象にする。
        if (! $this->last_crawled_at) {
            return true;
        }

        // 最終収集日時から設定間隔を過ぎていれば、スケジュール収集の対象にする。
        return $this->last_crawled_at->copy()->addMinutes($this->crawl_interval_minutes)->lte(now());
    }
}
