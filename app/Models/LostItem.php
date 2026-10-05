<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ของที่ลูกทริปลืมไว้ — ทีมงานโพสต์รูป เจ้าของกด "ของฉัน" แล้วนัดรับคืน
 */
class LostItem extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'schedule_id', 'message_id', 'posted_by_id', 'photo_path', 'description',
        'claimed_by_id', 'claimed_at', 'claim_note', 'returned_at', 'returned_note',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->returned_at !== null => self::STATUS_RETURNED,
            $this->claimed_by_id !== null => self::STATUS_CLAIMED,
            default => self::STATUS_OPEN,
        };
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return MediaDisk::url($this->photo_path);
    }
}
