<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ของหนึ่งรายการในใบซื้อของของรอบ — สำเนาจากรายการประจำทริป (template_item_id)
 * หรือของที่เพิ่มเฉพาะรอบนี้ (template_item_id = null) พร้อมสถานะซื้อแล้ว
 *
 * สถานะเป็นของทั้งทีมในรอบ ไม่ใช่รายคน — ใครติ๊กก็เห็นกันหมด จะได้ไม่ซื้อซ้ำ
 */
class ScheduleShoppingItem extends Model
{
    protected $fillable = [
        'schedule_id', 'template_item_id', 'name', 'quantity', 'unit', 'per_person',
        'note', 'sort_order', 'added_by_id', 'bought_at', 'bought_by_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'per_person' => 'boolean',
            'sort_order' => 'integer',
            'bought_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    public function boughtBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bought_by_id');
    }
}
