<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * สถานะการแจก/รับคืนอุปกรณ์เช่าของการจองหนึ่ง ๆ (หนึ่งแถวต่อชิ้นที่เช่า)
 *
 * ตัวรายการอุปกรณ์ยังเก็บเป็น snapshot อยู่บน bookings.selected_rentals เหมือนเดิม
 * ตารางนี้เก็บเฉพาะ "แจกแล้วหรือยัง / รับคืนแล้วหรือยัง" ที่สตาฟติ๊กหน้างาน
 */
class BookingRentalHandout extends Model
{
    protected $fillable = [
        'booking_id', 'item_name', 'quantity',
        'handed_out_at', 'handed_out_by_id',
        'returned_at', 'returned_by_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'handed_out_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function handedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_out_by_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_id');
    }
}
