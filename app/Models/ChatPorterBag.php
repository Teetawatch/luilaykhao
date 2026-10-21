<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatPorterBag extends Model
{
    protected $fillable = [
        'round_id', 'passenger_id', 'tag_no', 'weight_kg', 'note', 'created_by_id', 'returned_at', 'returned_by_id',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'float',
            'returned_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(ChatPorterRound::class, 'round_id');
    }
}
