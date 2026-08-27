<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * ทะเบียนคนขับ — แหล่งข้อมูลเดียวของคนขับ เก็บครั้งเดียวแล้วผูกกับรถได้หลายคัน
 * หน้ายานพาหนะไม่ต้องกรอกข้อมูลคนซ้ำอีก แค่เลือกชื่อจากทะเบียน
 *
 * รหัสส่ง GPS ผูกกับคน ไม่ใช่กับรถ: บัญชีล็อกอินอยู่ที่ `pin_user_id` ตั้ง PIN
 * ครั้งเดียวแล้วใช้ได้ทุกคันที่คนขับคนนี้ผูกอยู่ (ดู VehicleDriverService)
 */
class Driver extends Model
{
    use HasFactory;

    /** เตือนล่วงหน้ากี่วันก่อนใบขับขี่หมดอายุ */
    public const LICENSE_EXPIRY_WARNING_DAYS = 60;

    /** โฟลเดอร์บนดิสก์ส่วนตัวที่เก็บรูปใบขับขี่ */
    public const DOCUMENT_FOLDER = 'driver-documents';

    protected $fillable = [
        'name', 'phone', 'photo', 'notes', 'is_active',
        'license_number', 'license_type', 'license_expires_at', 'license_photo',
        'id_card', 'birth_date', 'address', 'line_id',
        'emergency_contact', 'emergency_phone',
    ];

    protected $hidden = [
        // เลขบัตรประชาชนถอดรหัสได้เฉพาะตอนที่ตั้งใจอ่าน ไม่หลุดไปกับ toArray()
        'id_card',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'license_expires_at' => 'date',
            'birth_date' => 'date',
            // เก็บเข้ารหัสที่ระดับฐานข้อมูล เหมือน User.id_card
            'id_card' => 'encrypted',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** บัญชีที่คนขับใช้ล็อกอินส่ง GPS (สร้างให้อัตโนมัติตอนตั้ง PIN) */
    public function pinUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pin_user_id');
    }

    /**
     * รอบเดินทางทั้งหมดที่คนขับคนนี้รับผิดชอบ ผ่านรถที่ผูกไว้ —
     * ใช้ตอบว่า "คนขับคนนี้ยังถูกใช้งานอยู่ไหม"
     */
    public function schedules(): HasManyThrough
    {
        return $this->hasManyThrough(TripSchedule::class, Vehicle::class, 'driver_id', 'vehicle_id');
    }

    /** ตั้ง PIN ไว้แล้วหรือยัง */
    public function hasPin(): bool
    {
        return filled($this->pinUser?->driver_pin_hash);
    }

    /**
     * เหลืออีกกี่วันใบขับขี่จะหมดอายุ (ติดลบ = หมดไปแล้ว) — null ถ้าไม่ได้กรอกวันไว้
     *
     * นับตามวันไทย: วันหมดอายุเป็นวันที่แบบ wall-clock ถ้าเทียบกับ now() (UTC)
     * ใบที่หมดวันนี้จะกลายเป็น "หมดไปแล้ว" ตั้งแต่ตีห้าของไทย
     */
    public function licenseDaysLeft(): ?int
    {
        if (! $this->license_expires_at) {
            return null;
        }

        $expiry = Carbon::createFromFormat('Y-m-d', $this->license_expires_at->format('Y-m-d'), 'Asia/Bangkok')
            ->startOfDay();

        return (int) Carbon::now('Asia/Bangkok')->startOfDay()->diffInDays($expiry, false);
    }

    /**
     * สถานะใบขับขี่สำหรับแสดงผล: unknown (ไม่ได้กรอกวัน) / expired / expiring / valid
     */
    public function licenseStatus(): string
    {
        $daysLeft = $this->licenseDaysLeft();

        return match (true) {
            $daysLeft === null => 'unknown',
            $daysLeft < 0 => 'expired',
            $daysLeft <= self::LICENSE_EXPIRY_WARNING_DAYS => 'expiring',
            default => 'valid',
        };
    }
}
