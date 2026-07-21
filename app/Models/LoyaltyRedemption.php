<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LoyaltyRedemption extends Model
{
    protected $fillable = [
        'user_id', 'reward_id', 'source', 'points_used', 'discount_value', 'coupon_code',
        'is_used', 'booking_id', 'expires_at',
    ];

    /** คูปองแลกด้วยแต้ม. */
    public const SOURCE_REWARD = 'reward';

    /** ของขวัญวันเกิดตามระดับสมาชิก — ไม่ได้ใช้แต้มแลก. */
    public const SOURCE_BIRTHDAY = 'birthday';

    protected function casts(): array
    {
        return [
            'is_used' => 'boolean',
            'discount_value' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * ส่วนลดเป็นบาทของคูปองนี้ — ใช้ค่าบนตัวคูปองก่อน (ของขวัญวันเกิด) ถ้าไม่มี
     * จึงถอยไปดูของรางวัลที่ผูกไว้ (คูปองที่แลกด้วยแต้ม)
     */
    public function discountBaht(): float
    {
        if ($this->discount_value !== null) {
            return (float) $this->discount_value;
        }

        return (float) ($this->reward?->discount_value ?? 0);
    }

    /** คูปองนี้ยังใช้ได้อยู่ไหมสำหรับผู้ใช้คนที่ระบุ. */
    public function isUsableBy(?int $userId): bool
    {
        return $userId !== null
            && $this->user_id === $userId
            && ! $this->is_used
            && $this->discountBaht() > 0
            && ! ($this->expires_at && $this->expires_at->isPast());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public static function generateCoupon(): string
    {
        return 'TRD-'.strtoupper(Str::random(8));
    }
}
