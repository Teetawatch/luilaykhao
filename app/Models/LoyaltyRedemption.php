<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRedemption extends Model
{
    protected $fillable = [
        'user_id', 'reward_id', 'points_used', 'coupon_code',
        'is_used', 'booking_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_used' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public static function generateCoupon(): string
    {
        return 'TRD-' . strtoupper(\Illuminate\Support\Str::random(8));
    }
}
