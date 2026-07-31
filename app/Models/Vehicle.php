<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;

    /**
     * รอบที่ไม่นับว่ารถ "ยังมีงาน" — รอบที่ยกเลิกหรือจบไปแล้ว
     */
    public const DORMANT_SCHEDULE_STATUSES = ['cancelled', 'completed'];

    protected $fillable = [
        'name', 'type', 'capacity', 'seat_layout',
        'license_plate', 'color', 'driver_id', 'driver_name', 'driver_phone', 'driver_user_id', 'images',
        'driver_photo', 'interior_video',
    ];

    protected function casts(): array
    {
        return [
            'seat_layout' => 'array',
            'images' => 'array',
            'capacity' => 'integer',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TripSchedule::class);
    }

    /**
     * รอบข้างหน้าที่ยังมีชีวิต — เกณฑ์เดียวที่ใช้ตัดสินว่ารถคันนี้ "ยังใช้งานอยู่"
     * หรือ "เลิกใช้แล้ว" (หน้าจัดการยานพาหนะซ่อนคันที่เลิกใช้ และการลบถาวร
     * ก็ยึดเกณฑ์เดียวกันนี้ ไม่งั้นรถที่ถูกซ่อนอาจลบไม่ได้)
     *
     * ใช้วันตามเวลาไทย เพราะ departure_date เป็นวันที่แบบ wall-clock ไทย
     */
    public function upcomingSchedules(): HasMany
    {
        return $this->schedules()
            ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
            ->whereNotIn('status', self::DORMANT_SCHEDULE_STATUSES);
    }

    /**
     * คนขับจากทะเบียนคนขับที่ผูกกับรถคันนี้
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    public function pickupPoints(): HasMany
    {
        return $this->hasMany(VehiclePickupPoint::class)->orderBy('region')->orderBy('sort_order');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function latestLocation(): HasOne
    {
        return $this->hasOne(VehicleLocation::class)->latestOfMany('recorded_at');
    }

    /**
     * บัญชีคนขับ (role: driver) ที่ใช้ PIN ส่ง GPS ผ่าน /driver/track
     */
    public function driverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    /**
     * รถคันนี้ตั้งรหัสส่ง GPS (PIN) ไว้แล้วหรือยัง
     */
    public function hasDriverPin(): bool
    {
        return $this->driverUser !== null && ! empty($this->driverUser->driver_pin_hash);
    }
}
