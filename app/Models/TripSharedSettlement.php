<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** การจ่ายคืนระหว่างเพื่อนร่วมทริป (ลูกหนี้ → เจ้าหนี้) — ดู SharedExpenseService */
class TripSharedSettlement extends Model
{
    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CASH = 'cash';

    protected $fillable = [
        'schedule_id', 'from_passenger_id', 'to_passenger_id', 'amount', 'method', 'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
