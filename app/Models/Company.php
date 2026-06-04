<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'official_url',
        'memo',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function watchSources()
    {
        return $this->hasMany(WatchSource::class);
    }

    public function collectedItems()
    {
        return $this->hasMany(CollectedItem::class);
    }
}
