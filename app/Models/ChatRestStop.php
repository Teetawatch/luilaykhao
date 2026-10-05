<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * จุดพักระหว่างทาง ("พัก 20 นาที กลับรถ 15:40") — ผูกกับการ์ดหนึ่งใบในห้องแชท
 */
class ChatRestStop extends Model
{
    public const MIN_MINUTES = 1;

    public const MAX_MINUTES = 180;

    /** เตือนคนที่ยังไม่ขึ้นรถก่อนถึงเวลากี่นาที */
    public const REMIND_BEFORE_MINUTES = 5;

    /** ลืมกด "ออกรถ" — ปิดการ์ดให้เองหลังเลยเวลานัดไปนานเท่านี้ */
    public const AUTO_DEPART_AFTER_MINUTES = 180;

    protected $fillable = [
        'schedule_id', 'message_id', 'created_by_id', 'place', 'return_at',
        'reminded_at', 'due_notified_at', 'departed_at',
    ];

    protected function casts(): array
    {
        return [
            'return_at' => 'datetime',
            'reminded_at' => 'datetime',
            'due_notified_at' => 'datetime',
            'departed_at' => 'datetime',
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

    public function boardings(): HasMany
    {
        return $this->hasMany(ChatRestStopBoarding::class, 'stop_id');
    }

    public function isDeparted(): bool
    {
        return $this->departed_at !== null;
    }
}
