<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeCompletion extends Model
{
    protected $fillable = ['user_id', 'challenge_key', 'period', 'completed_on', 'notified_at'];

    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
