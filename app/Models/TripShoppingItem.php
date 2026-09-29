<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ของที่ต้องซื้อทุกครั้งที่ทริปนี้ออกเดินทาง — แอดมินตั้งไว้ที่ทริป แล้วแต่ละรอบ
 * ก๊อปไปเป็น [ScheduleShoppingItem] ของตัวเอง
 */
class TripShoppingItem extends Model
{
    protected $fillable = [
        'trip_id', 'name', 'quantity', 'unit', 'per_person', 'note', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'per_person' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
