<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ผู้เดินทางหนึ่งคนที่กรอกข้อมูลของตัวเองเข้ามา ยังไม่ผูกกับการจอง
 *
 * ช่องเหมือน `BookingPassenger` ทั้งชุดโดยตั้งใจ เพื่อให้ตอนแอดมินกด
 * "ดึงไปจอง" เป็นการคัดลอกค่าตรง ๆ ไม่ต้องแปลงหรือเดาว่าช่องไหนตรงกับช่องไหน
 */
class CustomerIntakePerson extends Model
{
    /**
     * ข้อความยินยอมที่แสดงบนฟอร์ม — เก็บคู่กับเวลาที่ติ๊กไว้เป็นหลักฐาน
     * แก้ที่นี่ที่เดียว ทั้งฟอร์มและหลักฐานจึงพูดตรงกันเสมอ
     */
    public const CONSENT_TEXT = 'ยินยอมให้เก็บข้อมูลนี้เพื่อจัดการการเดินทางและทำประกัน';

    protected $fillable = [
        'customer_intake_id', 'is_lead', 'pickup_point_id', 'title', 'name', 'nickname', 'phone', 'email',
        'id_card', 'birth_date', 'blood_group', 'name_en', 'nationality',
        'passport_no', 'passport_expires_at', 'emergency_contact', 'emergency_phone',
        'allergies', 'health_notes', 'halal_food', 'dive_cert_level', 'cert_number', 'weight',
        'consent_at', 'consent_ip', 'consent_text',
    ];

    protected function casts(): array
    {
        return [
            'is_lead' => 'boolean',
            // เข้ารหัสระดับคอลัมน์เหมือน users / booking_passengers / saved_travellers
            'id_card' => 'encrypted',
            'passport_no' => 'encrypted',
            'allergies' => 'encrypted',
            'health_notes' => 'encrypted',
            'birth_date' => 'date',
            'passport_expires_at' => 'date',
            'halal_food' => 'boolean',
            'weight' => 'float',
            'consent_at' => 'datetime',
        ];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(CustomerIntake::class, 'customer_intake_id');
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(SchedulePickupPoint::class, 'pickup_point_id');
    }

    /** รูปแบบเดียวกับที่ฟอร์มผู้โดยสารของหน้า "จองแทนลูกค้า" ใช้ */
    public function toPassengerPayload(): array
    {
        return [
            // จุดขึ้นรถรายคน — หน้าจองแทนลูกค้ารับช่องนี้ตรง ๆ ต่อคน
            'pickup_point_id' => $this->pickup_point_id,
            'title' => $this->title,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'phone' => $this->phone,
            'email' => $this->email,
            'id_card' => $this->id_card,
            'name_en' => $this->name_en,
            'nationality' => $this->nationality,
            'passport_no' => $this->passport_no,
            'passport_expires_at' => $this->passport_expires_at?->toDateString(),
            'birth_date' => $this->birth_date?->toDateString(),
            'blood_group' => $this->blood_group,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'allergies' => $this->allergies,
            'health_notes' => $this->health_notes,
            'halal_food' => $this->halal_food,
            'dive_cert_level' => $this->dive_cert_level,
            'cert_number' => $this->cert_number,
            'weight' => $this->weight,
        ];
    }

    /**
     * ชื่อที่ปลอดภัยพอจะแสดงบนหน้าสาธารณะของกลุ่ม — เพื่อนที่เปิดลิงก์เห็นได้ว่า
     * ใครกรอกไปแล้วบ้าง โดยไม่เห็นเบอร์ เลขบัตร หรือข้อมูลสุขภาพของคนอื่น
     */
    public function publicLabel(): string
    {
        if (filled($this->nickname)) {
            return $this->nickname;
        }

        return trim(explode(' ', trim($this->name))[0] ?: 'ผู้เดินทาง');
    }
}
