<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionRunSource extends Model
{
    protected $fillable = [
        'collection_run_id',
        'watch_source_id',
        'company_name',
        'source_name',
        'source_url',
    ];

    public function collectionRun()
    {
        return $this->belongsTo(CollectionRun::class);
    }

    public function watchSource()
    {
        return $this->belongsTo(WatchSource::class);
    }

    public function displayName(): string
    {
        return trim(($this->company_name ? $this->company_name.' / ' : '').$this->source_name);
    }
}
