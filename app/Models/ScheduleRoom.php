<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ห้องพักหนึ่งห้องของรอบเดินทาง — ผู้พักอยู่ใน schedule_room_guests (อิงผู้โดยสาร
 * ไม่ใช่บัญชีแอป เพราะคนในกลุ่มหลายคนไม่มีแอป)
 */
class ScheduleRoom extends Model
{
    public const MAX_GUESTS = 20;

    protected $fillable = ['schedule_id', 'stay_label', 'name', 'note', 'sort_order'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function guests(): HasMany
    {
        return $this->hasMany(ScheduleRoomGuest::class, 'room_id');
    }
}
