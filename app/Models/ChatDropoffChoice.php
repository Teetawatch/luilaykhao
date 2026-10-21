<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatDropoffChoice extends Model
{
    protected $fillable = [
        'round_id', 'passenger_id', 'pickup_point_id', 'label', 'latitude', 'longitude', 'note',
        'chosen_by_id', 'approach_notified_at', 'dropped_at', 'dropped_by_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'approach_notified_at' => 'datetime',
            'dropped_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(ChatDropoffRound::class, 'round_id');
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null
            && ! ($this->latitude == 0 && $this->longitude == 0);
    }
}
