<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** การโอนเงินให้เจ้าของที่พักหนึ่งครั้ง — รวมทุกใบจองที่ถึงรอบจ่าย */
class StayPayout extends Model
{
    protected $fillable = ['stay_id', 'amount', 'booking_count', 'paid_at', 'reference', 'note', 'paid_by_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'booking_count' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(StayBooking::class, 'payout_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_id');
    }
}
