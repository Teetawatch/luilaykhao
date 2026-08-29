<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ตัวเลือกยานพาหนะของรอบเดินทาง (รถบัส / รถตู้ / เรือเร็ว) พร้อมส่วนต่างราคาต่อคน
 *
 * @see database/migrations/2026_08_29_130000_create_schedule_vehicle_options_table.php
 */
class ScheduleVehicleOption extends Model
{
    protected $table = 'schedule_vehicle_options';

    protected $fillable = [
        'schedule_id', 'label', 'transport_type', 'vehicle_id',
        'price_adjustment', 'seats', 'booked_seats', 'note', 'image_url', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_adjustment' => 'decimal:2',
            'seats' => 'integer',
            'booked_seats' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'vehicle_option_id');
    }

    /**
     * ที่นั่งที่เหลือของตัวเลือกนี้ — null = แอดมินไม่ได้กำหนดโควตาย่อย
     * (รับได้เท่าที่เพดานรวมของรอบยังเหลือ) UI ต้องเช็ค null ก่อนขึ้น "เหลือ N ที่"
     */
    public function getAvailableSeatsAttribute(): ?int
    {
        if ($this->seats === null) {
            return null;
        }

        return max(0, (int) $this->seats - (int) $this->booked_seats);
    }

    public function canFit(int $count): bool
    {
        $available = $this->available_seats;

        return $available === null || $available >= $count;
    }

    public function isSoldOut(): bool
    {
        return $this->available_seats !== null && $this->available_seats <= 0;
    }

    /**
     * ตัวเลือกนี้ใช้ผังที่นั่งของรอบได้ไหม
     *
     * ผังที่นั่งของรอบมาจากรถคันที่ผูกกับรอบคันเดียว และการล็อกที่นั่งก็คีย์ด้วย
     * (รอบ, รหัสที่นั่ง) — ถ้าปล่อยให้รถคันที่สองใช้ผังด้วย ที่นั่ง A1 ของบัสกับ
     * A1 ของตู้จะกลายเป็นที่นั่งเดียวกันในสายตาระบบล็อก คันที่ไม่ใช่รถหลักของรอบ
     * จึงเป็นแบบไม่ระบุที่นั่ง (ทีมงานจัดหน้างาน) จนกว่าจะมีผังแยกรายคันจริง ๆ
     */
    public function usesScheduleSeatMap(TripSchedule $schedule): bool
    {
        return $this->vehicle_id === null
            || (int) $this->vehicle_id === (int) $schedule->vehicle_id;
    }
}
