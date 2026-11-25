<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ส่วนของผู้โดยสารหนึ่งคนในค่าใช้จ่ายที่หารกัน */
class TripSharedExpenseShare extends Model
{
    protected $fillable = ['expense_id', 'passenger_id', 'amount'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(TripSharedExpense::class, 'expense_id');
    }
}
