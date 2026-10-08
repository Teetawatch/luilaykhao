<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การจ่าย "ยอดเพิ่มเติม" หนึ่งครั้งบนใบจองที่ยืนยันแล้ว
 *
 * ใบหนึ่งมีได้หลายแถว เพราะแอดมินเพิ่มของให้ได้หลายรอบ — ยอดที่ต้องจ่ายแต่ละครั้ง
 * ไม่ได้ผูกไว้ล่วงหน้า แต่คำนวณสดจาก [Booking::extraDueAmount] ตอนลูกค้ากดจ่าย
 */
class BookingExtraPayment extends Model
{
    protected $fillable = [
        'booking_id', 'amount', 'payment_method', 'payment_ref', 'slip_path',
        'transfer_datetime', 'slip_ocr_status', 'slip_ocr_result', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_datetime' => 'datetime',
            'slip_ocr_result' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
