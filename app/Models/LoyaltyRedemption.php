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
     * ค่าที่คูปองถืออยู่ — ใช้ค่าบนตัวคูปองก่อน (ของขวัญวันเกิด ซึ่งไม่มีของรางวัลผูก)
     * ถ้าไม่มีจึงถอยไปดูของรางวัลที่ผูกไว้ (คูปองที่แลกด้วยแต้ม)
     *
     * ความหมายของตัวเลขขึ้นกับชนิดของรางวัล ดู `discountFor()` — อย่าเอาไปลบจาก
     * ยอดตรง ๆ เพราะคูปองแบบเปอร์เซ็นต์จะกลายเป็นส่วนลดบาททันที
     */
    public function rewardValue(): float
    {
        if ($this->discount_value !== null) {
            return (float) $this->discount_value;
        }

        return (float) ($this->reward?->discount_value ?? 0);
    }

    /** ชนิดของรางวัล — คูปองวันเกิดไม่มีของรางวัลผูก ถือเป็นส่วนลดบาทตายตัว. */
    public function rewardType(): string
    {
        return (string) ($this->reward?->type ?? LoyaltyReward::TYPE_DISCOUNT_FIXED);
    }

    /**
     * ส่วนลดจริงเป็นบาทของคูปองใบนี้กับยอดที่ส่งเข้ามา
     *
     * - ส่วนลดบาท: ลดตามค่าที่ตั้งไว้ แต่ไม่เกินยอดรวม
     * - ส่วนลดเปอร์เซ็นต์: คิดจากยอดรวม (เดิมบั๊ก — เอา 10 ของ "ลด 10%" ไปลบเป็น 10 บาท)
     * - เช่าอุปกรณ์ฟรี: หักได้เฉพาะค่าเช่าอุปกรณ์ และไม่เกินเพดานที่ตั้งไว้
     *   (ตั้ง 0 = ฟรีเต็มจำนวนไม่จำกัดเพดาน)
     */
    public function discountFor(float $total, float $rentalsTotal = 0): float
    {
        $value = $this->rewardValue();

        return match ($this->rewardType()) {
            LoyaltyReward::TYPE_DISCOUNT_PERCENT => round(min($total * $value / 100, $total), 2),
            LoyaltyReward::TYPE_FREE_RENTAL => $value > 0
                ? round(min($value, $rentalsTotal), 2)
                : round($rentalsTotal, 2),
            default => round(min($value, $total), 2),
        };
    }

    /**
     * คูปองนี้ยังใช้ได้อยู่ไหมสำหรับผู้ใช้คนที่ระบุ
     *
     * ไม่ตรวจว่าลดได้กี่บาทตรงนี้ เพราะคูปองเช่าอุปกรณ์ฟรีจะลดได้ก็ต่อเมื่อการจอง
     * นั้นมีการเช่าอุปกรณ์ — ปฏิเสธที่นี่จะกลายเป็น "คูปองใช้ไม่ได้" ทั้งที่จริงแค่
     * ยังไม่ได้เลือกอุปกรณ์ ตัวเรียกเป็นคนบอกเหตุผลที่ตรงกว่า
     */
    public function isUsableBy(?int $userId): bool
    {
        return $userId !== null
            && $this->user_id === $userId
            && ! $this->is_used
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
