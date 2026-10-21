<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * สตาฟส่งมอบเงินหน้างานของรอบให้บริษัท — ยอดเป็นภาพ ณ ตอนกดส่ง
 */
class OnsiteMoneyHandover extends Model
{
    protected $fillable = [
        'schedule_id', 'user_id', 'cash_amount', 'transfer_amount', 'note',
        'submitted_at', 'received_by_id', 'received_at', 'received_note',
    ];

    protected function casts(): array
    {
        return [
            'cash_amount' => 'decimal:2',
            'transfer_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function total(): float
    {
        return round((float) $this->cash_amount + (float) $this->transfer_amount, 2);
    }
}
