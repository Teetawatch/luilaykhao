<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * อุปกรณ์เช่าหนึ่งก้อนที่ลูกค้าสั่งเพิ่มเองหลังจอง — ดู [RentalOrderService]
 *
 * `items` เป็น snapshot รูปร่างเดียวกับ bookings.selected_rentals ของที่อยู่ในก้อนนี้
 * ถูกรวมเข้าใบจองตั้งแต่ตอนสั่ง ก้อนที่ยกเลิกจึงต้องถอดออกจากใบจองด้วย
 */
class BookingRentalOrder extends Model
{
    public const STATUS_AWAITING = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'booking_id', 'user_id', 'items', 'amount', 'status',
        'paid_at', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
}
