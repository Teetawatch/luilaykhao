<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ส่วนแบ่งการชำระของสมาชิกหนึ่งคนในการจองแบบ "แบ่งจ่ายกลุ่ม"
 * ผลรวมของทุกส่วน = ยอดคงเหลือ (balance_amount) ของการจอง
 */
class BookingSplitShare extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'booking_id', 'member_id', 'passenger_id', 'label', 'amount',
        'status', 'pay_token', 'payment_method', 'payment_ref',
        'slip_path', 'transfer_datetime', 'slip_ocr_status', 'slip_ocr_result',
        'paid_at', 'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_datetime' => 'datetime',
            'slip_ocr_result' => 'array',
            'paid_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(BookingMember::class, 'member_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'passenger_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function payUrl(): string
    {
        return url('/pay-share/'.$this->pay_token);
    }

    /**
     * ชื่อที่ใช้แสดงผลของส่วนแบ่งนี้ — สมาชิกแอป > ผู้เดินทาง > ป้ายกำกับ
     */
    public function displayName(): string
    {
        $memberUser = $this->member?->user;
        if ($memberUser) {
            return $memberUser->nickname ?: $memberUser->name;
        }

        $passenger = $this->passenger;
        if ($passenger) {
            return $passenger->nickname ?: $passenger->name;
        }

        return $this->label ?: 'ผู้ร่วมทริป';
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(24));
        } while (static::where('pay_token', $token)->exists());

        return $token;
    }
}
