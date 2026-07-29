<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatMessage extends Model
{
    protected $fillable = [
        'schedule_id', 'user_id', 'reply_to_id', 'sender_role', 'system_key', 'body', 'image_path',
        'pinned_at', 'pinned_by_id', 'edited_at', 'is_deleted', 'mentions',
    ];

    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'edited_at' => 'datetime',
            'is_deleted' => 'boolean',
            'mentions' => 'array',
        ];
    }

    public function getImageUrlAttribute(): ?string
    {
        return MediaDisk::url($this->image_path);
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
}
