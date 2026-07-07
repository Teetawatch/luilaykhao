<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * โพสต์ในฟีดรูปหลังทริป — ลูกค้าที่เคยเดินทางจริงแชร์รูป + แคปชัน
 * เป็นฟีดสาธารณะต่อทริป (social proof / UGC)
 */
class TripPost extends Model
{
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    /** จำนวน report ที่ทำให้โพสต์ถูกซ่อนอัตโนมัติระหว่างรอแอดมินตรวจ */
    public const AUTO_HIDE_REPORTS = 5;

    protected $fillable = [
        'trip_id', 'schedule_id', 'user_id', 'caption', 'photos',
        'likes_count', 'comments_count', 'reports_count',
        'status', 'hidden_at', 'hidden_by',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'reports_count' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // ลบไฟล์รูปบน storage เมื่อโพสต์ถูกลบจริง
        static::deleting(function (TripPost $post) {
            foreach ($post->photos ?? [] as $photo) {
                $path = is_array($photo) ? ($photo['path'] ?? null) : null;
                $disk = is_array($photo) ? ($photo['disk'] ?? null) : null;
                if ($path && $disk) {
                    Storage::disk($disk)->delete($path);
                }
            }
        });
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(TripPostLike::class, 'post_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TripPostComment::class, 'post_id')->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TripPostReport::class, 'post_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
