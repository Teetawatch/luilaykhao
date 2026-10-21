<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatWellnessAnswer extends Model
{
    protected $fillable = [
        'check_id', 'passenger_id', 'status', 'note', 'answered_by_id', 'handled_at', 'handled_by_id',
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ChatWellnessCheck::class, 'check_id');
    }
}
