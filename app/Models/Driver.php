<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * ทะเบียนคนขับ — เก็บข้อมูลคนขับไว้ครั้งเดียว แล้วเลือกผูกกับรถได้หลายคัน
 * (ข้อมูล PIN/GPS ยังอยู่บนบัญชีผู้ใช้ที่ผูกกับรถผ่าน vehicles.driver_user_id)
 */
class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'photo', 'license_number', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * รอบเดินทางทั้งหมดที่คนขับคนนี้รับผิดชอบ ผ่านรถที่ผูกไว้ —
     * ใช้ตอบว่า "คนขับคนนี้ยังถูกใช้งานอยู่ไหม"
     */
    public function schedules(): HasManyThrough
    {
        return $this->hasManyThrough(TripSchedule::class, Vehicle::class, 'driver_id', 'vehicle_id');
    }
}
