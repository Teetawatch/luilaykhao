<?php

namespace App\Models;

use App\Services\TripActivityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Live Activity หนึ่งตัวที่ยัง "อยู่บนหน้าจอล็อก" ของลูกค้าหนึ่งเครื่อง
 *
 * @see TripActivityService
 */
class LiveActivity extends Model
{
    protected $fillable = [
        'user_id', 'booking_id', 'schedule_id',
        'platform', 'push_token', 'activity_id',
        'state', 'stage',
        'started_at', 'last_pushed_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'started_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }
}
