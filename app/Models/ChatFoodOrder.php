<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ออเดอร์ของหนึ่งคนในรอบรับออเดอร์อาหาร — items = [{name, qty}]
 * user_id ว่าง = สตาฟจดแทนคนที่ไม่ได้ใช้แอป (ชื่ออยู่ใน guest_name)
 */
class ChatFoodOrder extends Model
{
    protected $fillable = [
        'round_id', 'user_id', 'guest_name', 'items', 'skipped', 'entered_by_id',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'skipped' => 'boolean',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(ChatFoodRound::class, 'round_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayName(): string
    {
        return $this->user?->nickname ?: ($this->user?->name ?: ($this->guest_name ?: 'ผู้ร่วมทริป'));
    }
}
