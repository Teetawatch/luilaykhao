<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * เช็คพอยต์บนเส้นทางเดิน — ผูกกับการ์ดหนึ่งใบในห้องแชท
 */
class ChatTrailCheck extends Model
{
    public const MIN_CHECKPOINTS = 1;

    public const MAX_CHECKPOINTS = 8;

    /** การ์ดที่ลืมปิด ปิดเองหลังเปิดไปนานเท่านี้ — ทริปเดินขึ้นยอดไม่เกินวันเดียว */
    public const AUTO_CLOSE_AFTER_HOURS = 18;

    /** ขอพักซ้ำภายในเวลานี้ = คำขอเดิม ไม่เด้งหาทีมงานซ้ำ */
    public const HELP_REPEAT_MINUTES = 5;

    protected $fillable = [
        'schedule_id', 'message_id', 'created_by_id', 'title', 'checkpoints', 'finished_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'checkpoints' => 'array',
            'finished_at' => 'datetime',
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

    public function positions(): HasMany
    {
        return $this->hasMany(ChatTrailPosition::class, 'check_id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /** @return array<int, string> */
    public function checkpointNames(): array
    {
        return array_values(array_map('strval', $this->checkpoints ?? []));
    }

    public function lastIndex(): int
    {
        return count($this->checkpointNames()) - 1;
    }
}
