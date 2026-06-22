<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleItineraryItem extends Model
{
    protected $fillable = [
        'schedule_id', 'item_date', 'time', 'title', 'detail', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'item_date' => 'date',
            'sort_order' => 'integer',
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
}
