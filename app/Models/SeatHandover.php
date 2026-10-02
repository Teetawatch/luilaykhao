<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ลิงก์ส่งต่อที่นั่งของผู้เดินทางหนึ่งคน — ดู SeatHandoverService
 */
class SeatHandover extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'booking_id', 'passenger_id', 'token', 'status', 'transfers_ownership', 'note',
        'created_by', 'created_by_staff', 'expires_at',
        'claimed_by', 'claimed_at', 'cancelled_at', 'cancelled_by',
        'previous_name', 'new_name', 'previous_owner_id', 'previous_member_user_id',
        'terms_version', 'channel', 'ip',
    ];

    protected $hidden = ['ip'];

    protected function casts(): array
    {
        return [
            'transfers_ownership' => 'boolean',
            'created_by_staff' => 'boolean',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'passenger_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** ยังใช้รับที่นั่งได้อยู่ไหม (ยังไม่ถูกใช้ ไม่ถูกยกเลิก ไม่หมดอายุ) */
    public function isOpen(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /** สถานะที่หน้าจออ่าน — "expired" ไม่ได้เก็บลง DB คิดจากเวลาเสมอ */
    public function displayStatus(): string
    {
        if ($this->isPending() && $this->isExpired()) {
            return 'expired';
        }

        return $this->status;
    }

    public function url(): string
    {
        return url('/handover/'.$this->token);
    }
}
