<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'value',
        'trip_ids',
        'max_uses',
        'used_count',
        'claim_limit',
        'per_user_limit',
        'is_active',
        'is_flash_sale',
        'start_date',
        'end_date',
        'ends_at',
    ];

    protected $casts = [
        'trip_ids' => 'array',
        'claim_limit' => 'integer',
        'claimed_count' => 'integer',
        'per_user_limit' => 'integer',
        'is_active' => 'boolean',
        'is_flash_sale' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'ends_at' => 'datetime',
    ];

    /**
     * รูปเดียวของโค้ดส่วนลดที่ระบบเก็บและใช้ค้น: ตัดช่องว่างหัวท้าย + พิมพ์ใหญ่
     * ใช้กับคูปองแลกแต้ม (TRD-XXXXXXXX) ที่กรอกช่องเดียวกันได้ด้วย
     *
     * ไม่ตัดขีด/อักขระอื่นทิ้งแบบ GiftVoucher::normalizeCode — โค้ดที่แอดมินตั้งเองมีขีดได้
     * ภาษาไทยไม่มีตัวพิมพ์ จึงผ่าน mb_strtoupper ไปเหมือนเดิม
     */
    public static function normalizeCode(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }

    /** แอดมินพิมพ์ "Summer10" ก็เก็บเป็น "SUMMER10" — ลูกค้าพิมพ์แบบไหนก็เจอ */
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : self::normalizeCode($value);
    }

    /** แจกแบบจำนวนใบจำกัด — ต้องถือใบ (เก็บไว้) ถึงจะใช้ได้ ดู PromotionService */
    public function isClaimLimited(): bool
    {
        return $this->claim_limit !== null;
    }

    /** ใบที่ยังเก็บได้ — null = ไม่จำกัด */
    public function claimsLeft(): ?int
    {
        return $this->isClaimLimited()
            ? max(0, (int) $this->claim_limit - (int) $this->claimed_count)
            : null;
    }

    /** จำกัดทริปก็ต่อเมื่อ trip_ids เป็น array ที่ไม่ว่าง (null/[] = ทุกทริป) */
    public function appliesToTrip(int $tripId): bool
    {
        $ids = $this->trip_ids;
        if (! is_array($ids) || $ids === []) {
            return true;
        }

        return in_array($tripId, array_map('intval', array_filter($ids, 'is_numeric')), true);
    }

    public function savedBy()
    {
        return $this->hasMany(SavedPromotion::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
