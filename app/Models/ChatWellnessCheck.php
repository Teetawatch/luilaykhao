<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * เช็คอาการ "ยังไหวไหม" — ผูกกับการ์ดหนึ่งใบในห้องแชท
 */
class ChatWellnessCheck extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_TIRED = 'tired';

    public const STATUS_UNWELL = 'unwell';

    public const STATUSES = [self::STATUS_OK, self::STATUS_TIRED, self::STATUS_UNWELL];

    /** ถามไปแล้วกี่นาทียังไม่ตอบ — เตือนคนนั้นครั้งเดียว และบอกทีมงานว่าใครเงียบ */
    public const REMIND_AFTER_MINUTES = 10;

    /** การ์ดปิดเองหลังเปิดไปนานเท่านี้ — อาการเมื่อหลายชั่วโมงก่อนไม่ใช่อาการตอนนี้ */
    public const AUTO_CLOSE_AFTER_HOURS = 3;

    protected $fillable = ['schedule_id', 'message_id', 'created_by_id', 'label', 'reminded_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'reminded_at' => 'datetime',
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

    public function answers(): HasMany
    {
        return $this->hasMany(ChatWellnessAnswer::class, 'check_id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
