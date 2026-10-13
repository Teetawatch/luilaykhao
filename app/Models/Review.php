<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'user_id', 'reviewer_name', 'booking_id', 'trip_id', 'rating',
        'rating_guide', 'rating_vehicle', 'rating_food', 'rating_value', 'comment',
        'images', 'videos', 'admin_reply', 'admin_replied_by', 'admin_replied_at', 'is_approved',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'videos' => 'array',
            'is_approved' => 'boolean',
            'admin_replied_at' => 'datetime',
            'rating' => 'integer',
            'rating_guide' => 'integer',
            'rating_vehicle' => 'integer',
            'rating_food' => 'integer',
            'rating_value' => 'integer',
        ];
    }

    /**
     * รีวิวที่แนบรูปหรือวิดีโอจริง — คอลัมน์ทั้งสองเก็บ [] เมื่อไม่ได้แนบ
     * (ReviewController::store) จึงใช้ whereNotNull ไม่ได้ ต้องนับความยาวอาร์เรย์
     */
    public function scopeWithMedia(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereJsonLength('images', '>', 0)
            ->orWhereJsonLength('videos', '>', 0));
    }

    /**
     * ชื่อที่รีวิวนี้แสดงต่อสาธารณะ — รีวิวที่แอดมินส่งแทนลูกค้าเก็บชื่อลูกค้าไว้
     * ใน reviewer_name ที่เหลือใช้ชื่อบัญชีตามเดิม
     */
    public function authorName(string $fallback = 'ไม่ระบุชื่อ'): string
    {
        return $this->reviewer_name ?: ($this->user?->name ?? $fallback);
    }

    /** รูปโปรไฟล์ของแอดมินไม่ควรไปแปะข้างชื่อลูกค้า */
    public function authorAvatar(): ?string
    {
        return $this->reviewer_name ? null : $this->user?->avatar_url;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_replied_by');
    }
}
