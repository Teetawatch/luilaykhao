<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หลักฐานความยินยอมของลูกค้าก่อนใช้ฟีเจอร์ค้นหารูปตัวเองด้วยใบหน้าในอัลบั้มทริป
 *
 * สิ่งที่เก็บคือ "ใครกดยินยอม เมื่อไหร่ กับข้อความเวอร์ชันไหน" เท่านั้น
 * ตัวภาพใบหน้าและเวกเตอร์ใบหน้า (face descriptor) ไม่เคยออกจากเครื่องลูกค้า
 * จึงไม่มีข้อมูลชีวมาตรอยู่ในฐานข้อมูลนี้
 */
class FaceSearchConsent extends Model
{
    /**
     * เวอร์ชันข้อความขอความยินยอม — ต้องขยับทุกครั้งที่แก้เนื้อหาใน
     * resources/views/album.blade.php เพื่อให้คนที่เคยยินยอมถูกถามใหม่
     */
    public const CURRENT_VERSION = '2026-07-30';

    protected $fillable = [
        'trip_schedule_id', 'photo_token', 'subject_key', 'consent_version',
        'ip_address', 'user_agent', 'consented_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'trip_schedule_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
