<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCommission extends Model
{
    public const TYPE_COMMISSION = 'commission';

    /** ใบที่จ่ายคอมไปแล้วถูกคืนเงินทีหลัง — ยอดติดลบ หักจากรอบจ่ายถัดไป */
    public const TYPE_CLAWBACK = 'clawback';

    /** ใบจองยืนยันแล้ว รอเดินทาง */
    public const STATUS_PENDING = 'pending';

    /** เดินทางจบ ชำระครบ — รอโอน */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    /** ยกเลิก/คืนเงินก่อนจ่ายคอม หรือทีมงานตัดทิ้ง */
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'partner_id', 'booking_id', 'booking_ref', 'trip_title', 'departure_date',
        'type', 'status', 'seats', 'base_amount', 'rate_type', 'rate', 'amount',
        'approved_at', 'voided_at', 'void_reason', 'payout_id',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'seats' => 'integer',
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(PartnerPayout::class, 'payout_id');
    }
}
