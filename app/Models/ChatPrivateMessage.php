<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ข้อความในเธรดถามทีมงานแบบส่วนตัว — ตัวอักษร รูป หรือเสียง (อย่างใดอย่างหนึ่ง)
 */
class ChatPrivateMessage extends Model
{
    public const ROLE_CUSTOMER = 'customer';

    protected $fillable = [
        'thread_id', 'user_id', 'sender_role', 'body', 'image_path',
        'audio_path', 'audio_duration_ms', 'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'audio_duration_ms' => 'integer',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatPrivateThread::class, 'thread_id');
    }

    /** ผู้ส่ง (ลูกทริปเจ้าของเธรด หรือทีมงานคนใดคนหนึ่ง) */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return MediaDisk::url($this->image_path);
    }

    public function getAudioUrlAttribute(): ?string
    {
        return MediaDisk::url($this->audio_path);
    }

    public function fromCustomer(): bool
    {
        return $this->sender_role === self::ROLE_CUSTOMER;
    }

    /** ตัวอย่างข้อความสั้น ๆ สำหรับ push และรายการเธรด */
    public function preview(): string
    {
        return match (true) {
            $this->is_deleted => 'ข้อความถูกลบ',
            filled($this->body) => (string) $this->body,
            $this->audio_path !== null => ChatMessage::audioPreview($this->audio_duration_ms),
            $this->image_path !== null => '📷 รูปภาพ',
            default => '',
        };
    }
}
