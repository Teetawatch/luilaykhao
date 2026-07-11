<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การตอบรับข้อเสนอ Flexi-Price ของการจองหนึ่ง — เจ้าของการจองกด "ยอมรับ" หรือ
 * "ไม่ไปต่อ" ต่อส่วนต่างค่ารถที่ผู้จัดเสนอ
 */
class FlexiDepartureConsent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'offer_id', 'booking_id', 'status', 'surcharge_total', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'surcharge_total' => 'decimal:2',
            'responded_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(FlexiDepartureOffer::class, 'offer_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
