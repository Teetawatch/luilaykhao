<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ค่าใช้จ่ายที่เพื่อนร่วมทริปออกให้ก่อนแล้วหารกัน — ดู SharedExpenseService
 */
class TripSharedExpense extends Model
{
    public const SPLIT_EQUAL = 'equal';

    public const SPLIT_CUSTOM = 'custom';

    public const CATEGORIES = ['food', 'transport', 'ticket', 'stay', 'shopping', 'other'];

    protected $fillable = [
        'schedule_id', 'created_by_id', 'payer_passenger_id', 'title', 'category',
        'amount', 'split_mode', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(TripSharedExpenseShare::class, 'expense_id')->orderBy('id');
    }
}
