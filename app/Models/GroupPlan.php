<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GroupPlan extends Model
{
    protected $fillable = [
        'schedule_id', 'host_user_id', 'invite_code', 'name',
        'status', 'seat_count', 'expires_at', 'booking_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'seat_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupPlanMember::class);
    }

    public static function generateInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && ! $this->isExpired();
    }

    public function claimedMembers()
    {
        return $this->members->whereNotNull('seat_id')->where('status', '!=', 'left');
    }
}
