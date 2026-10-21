<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatMessage extends Model
{
    /** ข้อความเสียงยาวสุดกี่วินาที — แอปหยุดอัดเองที่ค่านี้ (ตรงกับ kVoiceMaxDuration ในแอป) */
    public const AUDIO_MAX_SECONDS = 120;

    /** สั้นกว่านี้คือกดพลาด ไม่ใช่ข้อความ */
    public const AUDIO_MIN_MS = 500;

    /** ขนาดไฟล์เสียงสูงสุด (KB) — AAC 2 นาทีราว 1 MB เผื่อไว้สำหรับเครื่องที่อัดบิตเรตสูง */
    public const AUDIO_MAX_KB = 6144;

    /**
     * นามสกุลที่รับ — แอปส่ง AAC ในกล่อง MPEG-4 (.m4a) แต่ libmagic ของแต่ละเครื่องอ่าน
     * ไฟล์เดียวกันออกมาเป็น audio/mp4, audio/x-m4a หรือ video/mp4 ก็ได้ จึงรับทั้งชุด
     */
    public const AUDIO_EXTENSIONS = ['m4a', 'mp4', 'aac', 'adts', 'mp3', 'wav', 'ogg', 'oga', 'weba', '3gp', '3gpp'];

    protected $fillable = [
        'schedule_id', 'user_id', 'reply_to_id', 'sender_role', 'system_key', 'body', 'image_path',
        'audio_path', 'audio_duration_ms', 'sticker_id',
        'pinned_at', 'pinned_by_id', 'edited_at', 'is_deleted', 'mentions',
        'ack_requested_at', 'ack_requested_by_id', 'ack_nudged_at',
    ];

    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'ack_requested_at' => 'datetime',
            'ack_nudged_at' => 'datetime',
            'edited_at' => 'datetime',
            'is_deleted' => 'boolean',
            'mentions' => 'array',
            'audio_duration_ms' => 'integer',
        ];
    }

    public function getImageUrlAttribute(): ?string
    {
        return MediaDisk::url($this->image_path);
    }

    public function getAudioUrlAttribute(): ?string
    {
        return MediaDisk::url($this->audio_path);
    }

    /** "🎤 ข้อความเสียง 0:12" — ใช้ในตัวอย่างข้อความ (push / รายการห้องแชท) */
    public static function audioPreview(?int $durationMs): string
    {
        $seconds = (int) round(max(0, (int) $durationMs) / 1000);

        return $seconds > 0
            ? sprintf('🎤 ข้อความเสียง %d:%02d', intdiv($seconds, 60), $seconds % 60)
            : '🎤 ข้อความเสียง';
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    /** สติกเกอร์ที่ข้อความนี้ส่ง — null = ข้อความธรรมดา หรือสติกเกอร์ถูกลบไปแล้ว */
    public function sticker(): BelongsTo
    {
        return $this->belongsTo(Sticker::class);
    }

    /** การ์ดแนะนำตัวที่ข้อความนี้แสดง */
    public function memberIntro(): HasOne
    {
        return $this->hasOne(ChatMemberIntro::class, 'message_id');
    }

    /** คนที่กด "รับทราบ" แล้ว (มีความหมายเมื่อ ack_requested_at ไม่ว่าง) */
    public function acks(): HasMany
    {
        return $this->hasMany(ChatMessageAck::class, 'message_id');
    }

    public function ackRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ack_requested_by_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    /**
     * โพลที่แนบมากับข้อความนี้ — ข้อความที่มีโพลจะเรนเดอร์เป็นการ์ดโหวตแทนบับเบิลปกติ
     */
    public function poll(): HasOne
    {
        return $this->hasOne(ChatPoll::class, 'message_id');
    }

    /**
     * รอบรับออเดอร์อาหารที่แนบกับข้อความนี้ — เรนเดอร์เป็นการ์ดสั่งอาหาร
     */
    public function foodRound(): HasOne
    {
        return $this->hasOne(ChatFoodRound::class, 'message_id');
    }

    /**
     * จุดพักที่แนบกับข้อความนี้ — เรนเดอร์เป็นการ์ดนับถอยหลังกลับรถ
     */
    public function restStop(): HasOne
    {
        return $this->hasOne(ChatRestStop::class, 'message_id');
    }

    /**
     * ของหายที่ประกาศผ่านข้อความนี้ (ของยังอยู่ต่อแม้ข้อความถูกลบ)
     */
    public function lostItem(): HasOne
    {
        return $this->hasOne(LostItem::class, 'message_id');
    }

    /**
     * การเก็บเงินหน้างานที่แนบกับข้อความนี้
     */
    public function collection(): HasOne
    {
        return $this->hasOne(ChatCollection::class, 'message_id');
    }

    /** เช็คพอยต์บนเส้นทางเดินที่แนบกับข้อความนี้ */
    public function trailCheck(): HasOne
    {
        return $this->hasOne(ChatTrailCheck::class, 'message_id');
    }

    /** จุดลงขากลับที่แนบกับข้อความนี้ */
    public function dropoffRound(): HasOne
    {
        return $this->hasOne(ChatDropoffRound::class, 'message_id');
    }

    /** เช็คอาการ "ยังไหวไหม" ที่แนบกับข้อความนี้ */
    public function wellnessCheck(): HasOne
    {
        return $this->hasOne(ChatWellnessCheck::class, 'message_id');
    }

    /** ฝากของลูกหาบที่แนบกับข้อความนี้ */
    public function porterRound(): HasOne
    {
        return $this->hasOne(ChatPorterRound::class, 'message_id');
    }
}
