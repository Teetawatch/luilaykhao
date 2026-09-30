<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ที่นั่งในรอบที่เพิ่งเปิดใหม่ ซึ่งกันไว้ให้ลูกค้าที่รอบเดิมถูกเลื่อนเพราะเหตุสุดวิสัย
 * (ForceMajeureService::announceNewRound) — คนทั่วไปจองที่นั่งเหล่านี้ไม่ได้จนกว่า
 * จะหมดเวลา ถูกปล่อย หรือเจ้าของเลือกรอบไปแล้ว
 *
 * นับรวมใน WaitlistService::heldSeats() และ TripSchedule::scopeWithHeldSeats()
 * ตัวเดียวกับที่คิวรอใช้ ทุกทางที่จองจึงเคารพการกันที่นั่งนี้โดยไม่ต้องแก้ทีละจุด
 */
class ForceMajeureSeatHold extends Model
{
    protected $fillable = [
        'schedule_id', 'booking_id', 'user_id', 'seat_count', 'expires_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'seat_count' => 'integer',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    /**
     * ยังกันอยู่จริง — ยังไม่ถูกปล่อย ยังไม่หมดเวลา และเจ้าของยังรอเลือกรอบอยู่
     * (เช็คใบจองซ้ำด้วย เผื่อทางไหนลืมปล่อย: ใบที่เลือกรอบแล้ว/ยกเลิก/ถูกย้อน
     * การเลื่อน ต้องไม่กินที่นั่งของคนอื่นต่อ)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('force_majeure_seat_holds.released_at')
            ->where('force_majeure_seat_holds.expires_at', '>', now())
            ->whereHas('booking', fn ($q) => $q
                ->whereNotNull('force_majeure_at')
                ->whereNull('force_majeure_resolved_at')
                ->whereIn('status', Booking::MODIFIABLE_STATUSES));
    }
}
