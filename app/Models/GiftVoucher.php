<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * บัตรของขวัญแบบระบุยอดเงิน
 *
 * วงจร: pending (สร้างแล้ว รอโอน) → under_review (ส่งสลิปแล้ว ระบบอ่านไม่ผ่าน รอทีมงาน)
 * → active (ใช้ได้) · rejected (สลิปไม่ผ่าน ส่งใหม่ได้) · cancelled (ยกเลิก)
 *
 * ยอดคงเหลือ (balance) เปลี่ยนผ่าน GiftVoucherService เท่านั้น ทุกการเปลี่ยนมีแถวใน
 * gift_voucher_transactions
 */
class GiftVoucher extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** ตัดตัวที่อ่านสับสน (0/O, 1/I/L) ออก — รหัสถูกพิมพ์ตามจากรูปหรือบอกปากต่อปาก */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_PREFIX = 'GV';

    private const CODE_BODY_LENGTH = 10;

    protected $fillable = [
        'code', 'purchaser_user_id', 'owner_user_id', 'amount', 'balance', 'status',
        'recipient_name', 'from_name', 'message', 'design', 'is_complimentary',
        'slip_path', 'slip_ocr_status', 'slip_ocr_result', 'payment_ref',
        'paid_at', 'expires_at', 'claimed_at',
        'reviewed_by_id', 'reviewed_at', 'review_note',
    ];

    protected $hidden = ['slip_ocr_result'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'slip_ocr_result' => 'array',
            'is_complimentary' => 'boolean',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchaser_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftVoucherTransaction::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public static function generateCode(): string
    {
        $alphabetLength = strlen(self::CODE_ALPHABET);

        do {
            $body = '';
            for ($i = 0; $i < self::CODE_BODY_LENGTH; $i++) {
                $body .= self::CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
            }
            $code = self::CODE_PREFIX.$body;
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * รหัสที่ผู้ใช้พิมพ์มา → รูปที่เก็บในฐานข้อมูล ("gv-abcde fghjk" → "GVABCDEFGHJK")
     */
    public static function normalizeCode(?string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $input));
    }

    /** รูปแบบรหัสบัตรของขวัญ — ใช้แยกจากโค้ดโปรโมชันที่ส่งมาในช่องเดียวกัน */
    public static function looksLikeCode(?string $input): bool
    {
        $code = self::normalizeCode($input);

        return strlen($code) === strlen(self::CODE_PREFIX) + self::CODE_BODY_LENGTH
            && str_starts_with($code, self::CODE_PREFIX);
    }

    /** "GVABCDEFGHJK" → "GV-ABCDE-FGHJK" อ่านและพิมพ์ตามง่ายกว่า */
    public function displayCode(): string
    {
        $body = substr($this->code, strlen(self::CODE_PREFIX));

        return self::CODE_PREFIX.'-'.substr($body, 0, 5).'-'.substr($body, 5);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** ใช้จ่ายได้ไหม (ไม่ดูว่าเป็นของใคร — ดู [canBeUsedBy]) */
    public function isSpendable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && (float) $this->balance > 0
            && ! $this->isExpired();
    }

    /**
     * บัตรที่ยังไม่มีใครผูกเข้าบัญชีเป็นของผู้ถือรหัส — ผูกแล้วใช้ได้เฉพาะเจ้าของ
     * ยอดที่เหลือหลังใช้ครั้งแรกจึงไม่หลุดไปให้คนอื่นที่บังเอิญเห็นรหัส
     */
    public function canBeUsedBy(?int $userId): bool
    {
        return $this->owner_user_id === null || $this->owner_user_id === $userId;
    }

    /** สถานะที่แสดงให้ลูกค้าเห็น — แยก "ใช้หมดแล้ว" และ "หมดอายุ" ออกจาก active */
    public function displayStatus(): string
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return $this->status;
        }

        if ((float) $this->balance <= 0) {
            return 'used_up';
        }

        return $this->isExpired() ? 'expired' : self::STATUS_ACTIVE;
    }

    public function shareUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/voucher/'.$this->code;
    }
}
