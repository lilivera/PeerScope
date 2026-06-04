<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'collected_item_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function collectedItem()
    {
        return $this->belongsTo(CollectedItem::class);
    }
}
