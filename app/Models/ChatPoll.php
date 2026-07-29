<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * โพลในห้องแชททริป — ผูกกับข้อความหนึ่งใบในห้อง (message_id) เพื่อให้โผล่ตาม
 * ลำดับเวลาเหมือนข้อความทั่วไป ตอบกลับ/ปักหมุดได้ตามปกติ
 */
class ChatPoll extends Model
{
    /** จำนวนตัวเลือกที่อนุญาต */
    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 6;

    protected $fillable = [
        'schedule_id', 'message_id', 'created_by_id', 'question',
        'allow_multiple', 'closes_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'allow_multiple' => 'boolean',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ChatPollOption::class, 'poll_id')->orderBy('sort_order');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ChatPollVote::class, 'poll_id');
    }

    /**
     * ปิดแล้วหรือยัง — ปิดด้วยมือ หรือเลยเวลาปิดอัตโนมัติ (ไม่ต้องมี job มาไล่ปิด)
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null
            || ($this->closes_at !== null && $this->closes_at->isPast());
    }
}
