<?php

namespace App\Models;

use App\Services\PermitSignatureService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * แบบฟอร์มอุทยานของสตาฟหนึ่งคนในรอบหนึ่ง — ลายเซ็น + ที่อยู่ที่ฟอร์มถามตอนเซ็น
 * ข้อมูลอื่นในฟอร์มมาจากโปรไฟล์ของสตาฟเสมอ (แก้โปรไฟล์แล้วฟอร์มเปลี่ยนตาม)
 * ดู StaffPermitService
 */
class StaffPermitSignature extends Model
{
    protected $fillable = ['schedule_id', 'user_id'];

    /** ลิงก์เซ็นและภาพลายเซ็นเป็นของเจ้าตัว — ส่งออกเฉพาะทางที่ตั้งใจ */
    protected $hidden = ['token', 'signature_path', 'sign_meta', 'extra'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'sign_meta' => 'array',
            'extra' => 'encrypted:array',
            'reminded_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StaffPermitSignature $row) {
            if (blank($row->token)) {
                $row->token = Str::random(32);
            }
        });

        // เซ็นใหม่หรือล้างลายเซ็น — ไฟล์เดิมไม่มีใครอ้างถึงแล้ว
        static::updated(function (StaffPermitSignature $row) {
            if ($row->wasChanged('signature_path') && filled($row->getOriginal('signature_path'))) {
                PermitSignatureService::deleteFile($row->getOriginal('signature_path'));
            }
        });

        static::deleted(function (StaffPermitSignature $row) {
            PermitSignatureService::deleteFile($row->signature_path);
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * สตาฟในรูปผู้เดินทาง (ไม่บันทึกลงฐานข้อมูล) — ให้การเติมแบบฟอร์ม ตัวอย่างบนจอ
     * และการนับว่าเซ็นครบหรือยังใช้ทางเดียวกับลูกทริปทุกจุด
     */
    public static function asPassenger(User $user, ?self $row): BookingPassenger
    {
        $passenger = new BookingPassenger;
        $passenger->forceFill([
            'title' => $user->title,
            'name' => $user->name,
            'nickname' => $user->nickname,
            'name_en' => $user->name_en,
            'birth_date' => $user->birth_date,
            'nationality' => $user->nationality,
            'id_card' => $user->id_card,
            'passport_no' => $user->passport_no,
            'blood_group' => $user->blood_group,
            'phone' => $user->phone,
            'email' => $user->hasPlaceholderEmail() ? null : $user->email,
            'emergency_contact' => $user->emergency_contact,
            'emergency_phone' => $user->emergency_phone,
            'allergies' => $user->allergies,
            'health_notes' => $user->health_notes,
            'permit_signature_path' => $row?->signature_path,
            'permit_signed_at' => $row?->signed_at,
            'permit_signed_form_id' => $row?->signed_form_id,
            'permit_extra' => $row?->extra,
        ]);
        $passenger->setRelation('booking', null);

        return $passenger;
    }
}
