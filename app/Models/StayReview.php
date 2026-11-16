<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StayReview extends Model
{
    protected $fillable = ['stay_id', 'stay_booking_id', 'user_id', 'rating', 'comment', 'host_reply', 'is_hidden'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_hidden' => 'boolean',
        ];
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(StayBooking::class, 'stay_booking_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
