<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * รอบรับออเดอร์อาหารในห้องแชททริป — ผูกกับข้อความหนึ่งใบ (message_id) เหมือนโพล
 * ลูกค้าพิมพ์เมนูของตัวเองบนรถ สตาฟเปิดสรุปรวมแล้วไปสั่งร้านทีเดียว
 */
class ChatFoodRound extends Model
{
    /** เมนูต่อคนต่อรอบ (รวมที่สั่งเผื่อคนข้าง ๆ) */
    public const MAX_ITEMS = 10;

    public const MAX_QTY = 20;

    public const MAX_MINUTES = 180;

    protected $fillable = [
        'schedule_id', 'message_id', 'created_by_id', 'title', 'note',
        'closes_at', 'closed_at', 'announced_at',
        'prices', 'promptpay_id', 'payee_name', 'billed_at',
    ];

    /** ราคาต่อจานสูงสุดที่รับ — กันพิมพ์เลขเกิน */
    public const MAX_PRICE = 10000;

    protected function casts(): array
    {
        return [
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
            'announced_at' => 'datetime',
            'prices' => 'array',
            'billed_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ChatFoodOrder::class, 'round_id')->orderBy('id');
    }

    /** ปิดรับแล้ว — สตาฟกดปิด หรือเลยเวลาปิดรับที่ตั้งไว้ */
    public function isClosed(): bool
    {
        return $this->closed_at !== null
            || ($this->closes_at !== null && $this->closes_at->isPast());
    }
}
