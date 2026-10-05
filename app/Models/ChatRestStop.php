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

    public const KIND_REST = 'rest';

    public const KIND_MEETUP = 'meetup';

    /** เตือนคนที่ยังไม่ขึ้นรถก่อนถึงเวลากี่นาที */
    public const REMIND_BEFORE_MINUTES = 5;

    /** นัดรวมพลเตือนเร็วกว่า — ต้องเผื่อเวลาแต่งตัว/เดินจากที่พัก */
    public const MEETUP_REMIND_BEFORE_MINUTES = 15;

    /** เตือนคืนก่อนวันนัดตอนกี่โมง (เวลาไทย) */
    public const MEETUP_EVE_HOUR = 20;

    /** นัดล่วงหน้าได้ไกลสุดกี่วัน */
    public const MEETUP_MAX_DAYS = 7;

    /** กด "มาถึงแล้ว" ได้ตั้งแต่กี่ชั่วโมงก่อนเวลานัด — กันกดเล่นตั้งแต่เมื่อคืน */
    public const MEETUP_ARRIVE_WINDOW_HOURS = 3;

    /** ลืมกด "ออกรถ" — ปิดการ์ดให้เองหลังเลยเวลานัดไปนานเท่านี้ */
    public const AUTO_DEPART_AFTER_MINUTES = 180;

    protected $fillable = [
        'schedule_id', 'message_id', 'created_by_id', 'kind', 'place', 'return_at',
        'reminded_at', 'eve_reminded_at', 'due_notified_at', 'departed_at',
    ];

    protected function casts(): array
    {
        return [
            'return_at' => 'datetime',
            'reminded_at' => 'datetime',
            'eve_reminded_at' => 'datetime',
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

    public function isMeetup(): bool
    {
        return $this->kind === self::KIND_MEETUP;
    }

    /** เตือนคนที่ยังไม่มาก่อนถึงเวลากี่นาที */
    public function remindBeforeMinutes(): int
    {
        return $this->isMeetup() ? self::MEETUP_REMIND_BEFORE_MINUTES : self::REMIND_BEFORE_MINUTES;
    }

    public function isDeparted(): bool
    {
        return $this->departed_at !== null;
    }
}
