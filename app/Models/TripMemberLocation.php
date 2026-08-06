<?php

namespace App\Models;

use App\Services\TripMemberLocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ตำแหน่งล่าสุดของคนหนึ่งคนในรอบเดินทางหนึ่งรอบ
 *
 * มีแถว = กำลังแชร์อยู่ ลบแถว = เลิกแชร์
 *
 * @see TripMemberLocationService
 */
class TripMemberLocation extends Model
{
    protected $fillable = [
        'schedule_id', 'user_id',
        'latitude', 'longitude', 'accuracy_m', 'heading', 'speed_kmh', 'altitude_m',
        'battery_level', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'float',
            'heading' => 'float',
            'speed_kmh' => 'float',
            'altitude_m' => 'float',
            'battery_level' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * ตำแหน่งที่ยัง "เชื่อได้" — เก่ากว่านี้แล้วการวาดหมุดบนแผนที่กลายเป็นการโกหก
     */
    public function scopeRecent(Builder $query, int $minutes): Builder
    {
        return $query->where('recorded_at', '>=', now()->subMinutes($minutes));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }
}
