<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CollectedItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'watch_source_id',
        'title',
        'url',
        'url_hash',
        'content_hash',
        'published_at',
        'detected_at',
        'summary',
        'ai_summary',
        'ai_summary_model',
        'ai_summary_generated_at',
        'body_text',
        'category',
        'pdf_storage_path',
        'pdf_original_filename',
        'pdf_mime_type',
        'pdf_file_size',
        'pdf_downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'detected_at' => 'datetime',
            'ai_summary_generated_at' => 'datetime',
            'pdf_downloaded_at' => 'datetime',
        ];
    }

    public function hasDownloadedPdf(): bool
    {
        // 実ファイルの有無確認はコントローラ側で行い、ここでは保存情報の存在だけを見る。
        return filled($this->pdf_storage_path);
    }

    public function hasAiSummary(): bool
    {
        return filled($this->ai_summary);
    }

    public function displaySummary(): ?string
    {
        return $this->ai_summary ?: $this->summary;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function watchSource()
    {
        return $this->belongsTo(WatchSource::class);
    }

    public function reads()
    {
        return $this->hasMany(ItemRead::class);
    }

    public function isReadBy(User $user): bool
    {
        return $this->reads->contains('user_id', $user->id);
    }
}
