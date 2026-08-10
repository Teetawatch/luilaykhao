<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การจ่ายเงินหนึ่งครั้งผ่านเกตเวย์ (Beam)
 *
 * การจองหนึ่งใบมีได้หลายแถว เพราะจ่ายกันคนละยอดคนละเวลา: มัดจำก่อน แล้วยอดคงเหลือ
 * ทีหลัง หรือผ่อนทีละงวด หรือเพื่อนแต่ละคนจ่ายส่วนของตัวเอง — purpose + purpose_id
 * คือตัวบอกว่าเงินก้อนนี้ไปลงที่แถวไหนตอน settle
 */
class Payment extends Model
{
    use HasFactory;

    /** ยอดเต็มของการจอง. */
    public const PURPOSE_FULL = 'full';

    /** มัดจำ (ยอดคงเหลือตามมาทีหลัง). */
    public const PURPOSE_DEPOSIT = 'deposit';

    /** ส่วนของเจ้าของในการแบ่งจ่ายกลุ่ม. */
    public const PURPOSE_SPLIT = 'split';

    /** งวดแรกของการผ่อน — จ่ายพร้อมยืนยันที่นั่ง แถวงวดยังไม่มีตอนออก QR. */
    public const PURPOSE_INSTALLMENT = 'installment';

    /** งวดที่ 2 เป็นต้นไป — การจอง confirmed แล้ว purpose_id = installment_payments.id. */
    public const PURPOSE_INSTALLMENT_DUE = 'installment_due';

    /** ยอดคงเหลือหลังจ่ายมัดจำ. */
    public const PURPOSE_BALANCE = 'balance';

    /** ส่วนแบ่งของเพื่อน — purpose_id = booking_split_shares.id. */
    public const PURPOSE_SPLIT_SHARE = 'split_share';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'booking_id', 'purpose', 'purpose_id', 'provider', 'provider_charge_id',
        'reference_id', 'amount', 'currency', 'status', 'payment_method_type',
        'user_id', 'expires_at', 'succeeded_at', 'failure_code',
        'raw_response', 'raw_webhook',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'raw_response' => 'array',
            'raw_webhook' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** ยังรอเงินอยู่และ QR ยังไม่หมดอายุ — ที่นั่งต้องไม่ถูก timer ยกเลิกทิ้ง. */
    public function isAwaitingPayment(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    /** Beam คิดเป็นสตางค์ ไม่ใช่บาท. */
    public function amountInSatang(): int
    {
        return (int) round((float) $this->amount * 100);
    }
}
