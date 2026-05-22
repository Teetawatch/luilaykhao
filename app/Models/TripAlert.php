<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripAlert extends Model
{
    protected $fillable = [
        'user_id', 'trip_id',
        'alert_price_drop', 'alert_new_schedule', 'alert_low_seats',
        'last_notified_price', 'low_seat_threshold',
    ];

    protected function casts(): array
    {
        return [
            'alert_price_drop' => 'boolean',
            'alert_new_schedule' => 'boolean',
            'alert_low_seats' => 'boolean',
            'last_notified_price' => 'decimal:2',
            'low_seat_threshold' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(TripAlertDispatch::class);
    }
}
