<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CollectionError extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_run_id',
        'watch_source_id',
        'error_type',
        'error_message',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function run()
    {
        return $this->belongsTo(CollectionRun::class, 'collection_run_id');
    }

    public function watchSource()
    {
        return $this->belongsTo(WatchSource::class);
    }
}
