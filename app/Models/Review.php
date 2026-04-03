<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'user_id', 'booking_id', 'trip_id', 'rating', 'comment',
        'images', 'admin_reply', 'admin_replied_by', 'admin_replied_at', 'is_approved',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'is_approved' => 'boolean',
            'admin_replied_at' => 'datetime',
            'rating' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_replied_by');
    }
}
