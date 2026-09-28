<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * เหรียญพิชิตของคนหนึ่งคนในรอบหนึ่งรอบ — ถูกสร้าง ย้าย และลบโดย
 * App\Services\MedalService เท่านั้น ไม่มีใครเขียนแถวนี้ตรง ๆ
 */
class TripMedal extends Model
{
    protected $fillable = [
        'user_id', 'trip_id', 'schedule_id', 'booking_id',
        'finisher_no', 'earned_on', 'share_token', 'shape', 'seen_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'finisher_no' => 'integer',
            'earned_on' => 'date',
            'seen_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public static function newShareToken(): string
    {
        do {
            $token = Str::lower(Str::random(16));
        } while (static::where('share_token', $token)->exists());

        return $token;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function kudos(): HasMany
    {
        return $this->hasMany(MedalKudos::class, 'medal_id');
    }
}
