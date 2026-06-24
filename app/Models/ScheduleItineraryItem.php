<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleItineraryItem extends Model
{
    protected $fillable = [
        'schedule_id', 'item_date', 'time', 'title', 'detail', 'link', 'sort_order',
        'created_by', 'reached_at', 'reached_by',
    ];

    protected function casts(): array
    {
        return [
            'item_date' => 'date',
            'sort_order' => 'integer',
            'reached_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reached_by');
    }
}
