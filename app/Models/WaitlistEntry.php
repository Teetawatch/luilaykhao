<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    protected $fillable = [
        'user_id', 'schedule_id', 'seat_count', 'priority', 'status', 'offered_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'seat_count' => 'integer',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['waiting', 'offered']);
    }
}
