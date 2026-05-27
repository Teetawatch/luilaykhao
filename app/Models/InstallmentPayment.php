<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPayment extends Model
{
    protected $fillable = [
        'booking_id', 'installment_no', 'amount', 'due_date',
        'status', 'payment_method', 'payment_ref', 'paid_at',
        'slip_path', 'transfer_datetime', 'slip_ocr_status', 'slip_ocr_result',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'due_date'   => 'date',
            'paid_at'             => 'datetime',
            'transfer_datetime'   => 'datetime',
            'slip_ocr_result'     => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
