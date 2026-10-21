<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * จุดลงขากลับ — ผูกกับการ์ดหนึ่งใบในห้องแชท
 */
class ChatDropoffRound extends Model
{
    /** รถเข้าใกล้จุดลงในรัศมีนี้ (เส้นตรง) — แจ้งคนที่จะลงให้เตรียมของ ~5-10 นาทีก่อนถึง */
    public const APPROACH_KM = 4.0;

    /** พิกัดรถเก่ากว่านี้ไม่ใช้ตัดสินว่าใกล้ถึง */
    public const LOCATION_FRESH_MINUTES = 10;

    /** การ์ดที่ลืมปิด ปิดเองหลังเปิดไปนานเท่านี้ */
    public const AUTO_CLOSE_AFTER_HOURS = 20;

    protected $fillable = ['schedule_id', 'message_id', 'created_by_id', 'closed_at'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function choices(): HasMany
    {
        return $this->hasMany(ChatDropoffChoice::class, 'round_id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
