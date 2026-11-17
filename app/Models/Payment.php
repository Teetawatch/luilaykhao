<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การจ่ายเงินหนึ่งครั้งผ่านเกตเวย์ (Beam)
 *
 * แถวหนึ่งชี้ไปที่การจองทริป (booking_id) หรือใบจองที่พัก (stay_booking_id) อย่างใด
 * อย่างหนึ่ง — ที่พักจ่ายก้อนเดียวเต็มจำนวนเสมอ (purpose = stay)
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

    /** ยอดเพิ่มเติมบนใบที่ยืนยันแล้ว — ดู Booking::extraDueAmount(). */
    public const PURPOSE_EXTRA = 'extra';

    /** ค่าที่พักเต็มจำนวน — แถวนี้ชี้ stay_booking_id ไม่ใช่ booking_id. */
    public const PURPOSE_STAY = 'stay';

    /**
     * ชื่อของเราเองสำหรับ "ส่งลูกค้าไปหน้าชำระเงินของ Beam" (Payment Link) — ลูกค้า
     * เลือกบัตร / ผ่อน / e-Wallet / Apple Pay / Google Pay บนหน้านั้นเอง
     * ไม่ใช่ค่าที่ส่งเป็น paymentMethodType ให้ Beam
     */
    public const METHOD_CHECKOUT = 'CHECKOUT';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'booking_id', 'stay_booking_id', 'purpose', 'purpose_id', 'provider', 'provider_charge_id', 'provider_link_id',
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

    public function stayBooking(): BelongsTo
    {
        return $this->belongsTo(StayBooking::class);
    }

    /** ใบชำระค่าที่พัก (ไม่ใช่ค่าทริป). */
    public function isStay(): bool
    {
        return $this->stay_booking_id !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    /** ใบที่ส่งลูกค้าไปหน้าชำระเงินของ Beam (ไม่ใช่ charge ที่เราออกเอง). */
    public function isCheckout(): bool
    {
        return $this->payment_method_type === self::METHOD_CHECKOUT;
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

    /**
     * ก้อนที่ส่งให้ client — ใช้ร่วมกันทั้งค่าทริปและค่าที่พัก หน้าจอจึงใช้โค้ดชุดเดียว
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        $raw = $this->raw_response ?? [];

        return [
            'payment_id' => $this->id,
            'charge_id' => $this->provider_charge_id,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'payment_method_type' => $this->payment_method_type,
            // หน้าชำระเงินของ Beam (บัตร/ผ่อน/e-Wallet/Apple Pay/Google Pay) ตอบแค่ url —
            // ให้ client เห็นเป็น REDIRECT แบบเดียวกับแอปธนาคาร จะได้มีทางเดินทางเดียว
            'action_required' => $this->isCheckout() ? 'REDIRECT' : ($raw['actionRequired'] ?? null),
            // PNG ของ QR — client แสดงตรงๆ ได้เลย ไม่ต้องประกอบ payload เอง
            'qr_image_base64' => data_get($raw, 'encodedImage.imageBase64Encoded'),
            'qr_raw_data' => data_get($raw, 'encodedImage.rawData'),
            'redirect_url' => $this->isCheckout()
                ? ($raw['url'] ?? null)
                : data_get($raw, 'redirect.redirectUrl'),
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
