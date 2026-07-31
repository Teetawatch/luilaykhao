<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyReward extends Model
{
    /** ส่วนลดเป็นบาทตายตัว — `discount_value` คือจำนวนบาท. */
    public const TYPE_DISCOUNT_FIXED = 'discount_fixed';

    /** ส่วนลดเป็นเปอร์เซ็นต์ของยอดจอง — `discount_value` คือเปอร์เซ็นต์. */
    public const TYPE_DISCOUNT_PERCENT = 'discount_percent';

    /**
     * เช่าอุปกรณ์ฟรี — `discount_value` คือเพดานค่าเช่าที่ยกเว้นให้ (บาท), 0 = ไม่จำกัด
     * หักได้เฉพาะส่วนค่าเช่าอุปกรณ์ของใบจอง ไม่ลดค่าทริป
     */
    public const TYPE_FREE_RENTAL = 'free_rental';

    /** ชนิดที่ใช้งานได้จริงตอนนี้ — ของรางวัลที่ต้องส่งมอบของ (free_item) ยังไม่มี flow รองรับ. */
    public const TYPES = [
        self::TYPE_DISCOUNT_FIXED,
        self::TYPE_DISCOUNT_PERCENT,
        self::TYPE_FREE_RENTAL,
    ];

    protected $fillable = [
        'name', 'description', 'type', 'points_required',
        'discount_value', 'is_active', 'stock',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'discount_value' => 'decimal:2',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class, 'reward_id');
    }
}
