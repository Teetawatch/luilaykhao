<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * สมุดผู้ร่วมเดินทาง — คนที่ผู้ใช้เคยพาไปด้วยและอยากเก็บไว้ใช้ซ้ำ
 *
 * มีเพื่อไม่ต้องพิมพ์ 11 ช่องต่อคนใหม่ทุกครั้งที่จอง ข้อมูลอ่อนไหว
 * (เลขบัตร/แพ้อาหาร/โรคประจำตัว) เข้ารหัสที่ระดับคอลัมน์เหมือน `User`
 * และ `BookingPassenger`
 */
class SavedTraveller extends Model
{
    protected $fillable = [
        'user_id', 'label', 'title', 'name', 'nickname', 'phone', 'email',
        'id_card', 'birth_date', 'blood_group', 'emergency_contact',
        'emergency_phone', 'allergies', 'health_notes', 'halal_food',
        'name_en', 'nationality', 'passport_no', 'passport_expires_at',
        'last_used_at', 'times_used',
    ];

    protected function casts(): array
    {
        return [
            'id_card' => 'encrypted',
            'passport_no' => 'encrypted',
            'passport_expires_at' => 'date',
            'allergies' => 'encrypted',
            'health_notes' => 'encrypted',
            'birth_date' => 'date',
            'halal_food' => 'boolean',
            'last_used_at' => 'datetime',
            'times_used' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** รูปแบบเดียวกับที่ฟอร์มผู้โดยสารในแอปใช้ เพื่อเติมลงฟอร์มได้ตรง ๆ */
    public function toPassengerPayload(): array
    {
        return [
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
        ];
    }

    /** บันทึกว่าเพิ่งถูกใช้ เพื่อให้คนที่ใช้บ่อยลอยขึ้นบนสุด */
    public function markUsed(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'times_used' => $this->times_used + 1,
        ])->save();
    }
}
