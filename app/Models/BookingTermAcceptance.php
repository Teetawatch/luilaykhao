<?php

namespace App\Models;

use App\Support\LegalPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * หลักฐานว่าลูกค้าเห็นและกดยอมรับเงื่อนไขข้อความใด ผ่านช่องทางไหน เมื่อไหร่
 *
 * เขียนครั้งเดียวตอนสร้างใบจองแล้วห้ามแก้ — การแก้หลักฐานทีหลังได้ทำให้มัน
 * ไม่ใช่หลักฐาน โมเดลจึงปฏิเสธ update ทุกกรณี (ดู booted())
 */
class BookingTermAcceptance extends Model
{
    public const CHANNELS = ['web', 'app', 'liff'];

    public const CHANNEL_UNKNOWN = 'unknown';

    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_id', 'booking_ref', 'user_id',
        'accepted_by_name', 'accepted_by_email', 'accepted_by_phone',
        'terms_version', 'terms_lines', 'terms_hash',
        'channel', 'ip_address', 'user_agent', 'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'terms_lines' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // คืน false = Eloquent ยกเลิกการ save เงียบ ๆ — โยน exception แทน
        // เพื่อให้โค้ดที่พยายามแก้หลักฐานพังให้เห็นตั้งแต่ตอนเขียน
        static::updating(function () {
            throw new \LogicException('หลักฐานการยอมรับเงื่อนไขแก้ไขไม่ได้');
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ข้อความที่เก็บไว้ยังตรงกับลายนิ้วมือตอนบันทึกหรือไม่ */
    public function isIntact(): bool
    {
        return hash_equals(
            $this->terms_hash,
            LegalPolicy::fingerprint($this->terms_version, (array) $this->terms_lines),
        );
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'web' => 'เว็บไซต์',
            'app' => 'แอปพลิเคชัน',
            'liff' => 'LINE',
            default => 'ไม่ระบุช่องทาง',
        };
    }
}
