<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เส้นทางที่ผู้ใช้ "เดินจริง" ในหนึ่งรอบเดินทาง — บันทึกจาก GPS ของเครื่องเอง
 *
 * ต่างจาก `trips.route_track` ตรงที่อันนั้นคือเส้นทางของทริป (แอดมินอัปโหลด)
 * ส่วนอันนี้คือของคน ๆ นั้น จึงเอาไปตอบได้ว่าเดินไปจริงเท่าไหร่ ไม่ใช่ตัวเลข
 * ประมาณการของเส้นทาง
 */
class TripTrack extends Model
{
    protected $fillable = [
        'user_id', 'schedule_id', 'booking_id', 'points',
        'distance_km', 'elevation_gain_m', 'elevation_loss_m',
        'max_elevation_m', 'moving_seconds', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'array',
            'distance_km' => 'decimal:2',
            'elevation_gain_m' => 'integer',
            'elevation_loss_m' => 'integer',
            'max_elevation_m' => 'integer',
            'moving_seconds' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
