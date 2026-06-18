<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CollectionRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'started_at',
        'finished_at',
        'status',
        'target_count',
        'created_count',
        'updated_count',
        'error_count',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function errors()
    {
        return $this->hasMany(CollectionError::class);
    }

    public function targetSources()
    {
        return $this->hasMany(CollectionRunSource::class);
    }

    public function targetSummary(int $limit = 3): string
    {
        $sources = $this->relationLoaded('targetSources')
            ? $this->targetSources
            : $this->targetSources()->get();

        if ($sources->isEmpty()) {
            return '-';
        }

        $names = $sources
            ->take($limit)
            ->map(fn (CollectionRunSource $source): string => $source->displayName())
            ->all();

        $remaining = $sources->count() - count($names);

        return implode('、', $names).($remaining > 0 ? ' ほか'.$remaining.'件' : '');
    }

    public function statusLabel(): string
    {
        // DBには機械向けの状態値を保存し、画面では日本語ラベルへ変換する。
        return match ($this->status) {
            'running' => '実行中',
            'success' => '成功',
            'warning' => '一部失敗',
            'failed' => '失敗',
            default => $this->status,
        };
    }

    public function statusBadgeClass(): string
    {
        // ログ一覧・詳細で同じ色を使えるよう、状態ごとのBootstrapクラスをモデルに寄せる。
        return match ($this->status) {
            'running' => 'text-bg-primary',
            'success' => 'text-bg-success',
            'warning' => 'text-bg-warning',
            'failed' => 'text-bg-danger',
            default => 'text-bg-secondary',
        };
    }
}
