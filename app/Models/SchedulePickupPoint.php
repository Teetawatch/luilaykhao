<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulePickupPoint extends Model
{
    protected $table = 'schedule_pickup_points';

    protected $fillable = [
        'schedule_id', 'region', 'region_label', 'pickup_location',
        'price', 'map_url', 'latitude', 'longitude', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
            'sort_order' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }
}
