<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;

    /**
     * ประเภทยานพาหนะที่ลงทะเบียนได้ — คอลัมน์ `type` เป็นสตริงธรรมดา ตัวคุมค่าจริง
     * คือค่าคงที่นี้ (ใช้ใน validation) เหมือน TripSchedule::TRANSPORT_TYPES
     * ('flight' ไม่อยู่ในนี้ เพราะเครื่องบินไม่ใช่รถของเรา ไม่มีคนขับ/ผังที่นั่งให้จัดการ)
     */
    public const TYPES = ['van', 'boat', 'bus'];

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
     * รอบที่ยังไม่จบ — เกณฑ์เดียวที่ใช้ตัดสินว่ารถคันนี้ "ยังใช้งานอยู่" หรือไม่
     * (หน้าจัดการยานพาหนะแยกแท็บตามนี้ และการลบถาวรก็ยึดเกณฑ์เดียวกันนี้
     * ไม่งั้นรถที่ถูกซ่อนอาจลบไม่ได้ หรือรถที่ยังมีงานหลุดไปอยู่ในกองที่ลบได้)
     *
     * นับจากวันกลับ ไม่ใช่วันออก — รอบหลายวันที่ออกไปแล้วแต่ยังไม่กลับ รถยังอยู่บนถนน
     * ใช้วันตามเวลาไทย เพราะ departure_date/return_date เป็นวันที่แบบ wall-clock ไทย
     */
    public function upcomingSchedules(): HasMany
    {
        return self::stillRunning($this->schedules());
    }

    /**
     * รอบที่ใช้รถคันนี้ผ่าน "ตัวเลือกยานพาหนะ" (รอบเดียววิ่งทั้งบัสและตู้) — รถไม่ได้เป็น
     * vehicle_id ของรอบ แต่ผังที่นั่งของตัวเลือกนั้นวาดจากรถคันนี้
     */
    public function optionSchedules(): HasManyThrough
    {
        return $this->hasManyThrough(
            TripSchedule::class, ScheduleVehicleOption::class,
            'vehicle_id', 'id', 'id', 'schedule_id',
        );
    }

    public function upcomingOptionSchedules(): HasManyThrough
    {
        return self::stillRunning($this->optionSchedules());
    }

    /**
     * สถานะการใช้งานของรถ จากตัวนับที่ withUsage() โหลดมา
     *   active     — มีรอบที่ยังไม่จบ
     *   never_used — ไม่เคยถูกใส่ในรอบไหนเลย
     *   past       — เคยวิ่ง แต่ทุกรอบผ่านไปแล้ว (หรือถูกยกเลิก/ปิดจบ)
     */
    public function usageStatus(): string
    {
        if (($this->upcoming_schedules_count ?? 0) + ($this->upcoming_option_schedules_count ?? 0) > 0) {
            return 'active';
        }

        return ($this->schedules_count ?? 0) + ($this->option_schedules_count ?? 0) > 0 ? 'past' : 'never_used';
    }

    /**
     * ยังมีรอบที่ไม่จบใช้รถคันนี้อยู่ไหม — guard ของการลบ (ตรงกับ usageStatus() === 'active')
     */
    public function isInUse(): bool
    {
        return $this->upcomingSchedules()->exists() || $this->upcomingOptionSchedules()->exists();
    }

    /**
     * โหลดตัวนับที่ usageStatus() / VehicleResource ต้องใช้
     */
    public function scopeWithUsage(Builder $query): Builder
    {
        return $query
            ->withCount(['schedules', 'upcomingSchedules', 'optionSchedules', 'upcomingOptionSchedules'])
            ->withMax('schedules', 'departure_date')
            ->withMax('optionSchedules as option_schedules_max_departure_date', 'trip_schedules.departure_date');
    }

    /**
     * กรองตามสถานะการใช้งาน — active | never_used | past (ค่าเดียวกับ usageStatus())
     */
    public function scopeUsage(Builder $query, string $status): Builder
    {
        $inUse = fn ($q) => $q->has('upcomingSchedules')->orHas('upcomingOptionSchedules');
        $everUsed = fn ($q) => $q->has('schedules')->orHas('optionSchedules');

        return match ($status) {
            'active' => $query->where($inUse),
            'never_used' => $query->whereNot($everUsed),
            'past' => $query->where($everUsed)->whereNot($inUse),
            default => $query,
        };
    }

    /**
     * @template T of HasMany|HasManyThrough
     *
     * @param  T  $relation
     * @return T
     */
    private static function stillRunning(HasMany|HasManyThrough $relation): HasMany|HasManyThrough
    {
        return $relation
            ->whereDate('trip_schedules.return_date', '>=', now('Asia/Bangkok')->toDateString())
            ->whereNotIn('trip_schedules.status', self::DORMANT_SCHEDULE_STATUSES);
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
