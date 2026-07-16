<?php

namespace App\Models;

use App\Jobs\ResolvePickupPointCoordinates;
use App\Support\GoogleMapsUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulePickupPoint extends Model
{
    protected $table = 'schedule_pickup_points';

    protected $fillable = [
        'schedule_id', 'region', 'region_label', 'pickup_location',
        'price', 'map_url', 'image_url', 'latitude', 'longitude', 'notes', 'pickup_time', 'sort_order',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    protected static function booted(): void
    {
        // Auto-fill coordinates from the pasted Google Maps URL so a point
        // pinned only with a share link still shows on the driver map. An
        // explicit lat/lng always wins; short links are resolved in the
        // background (they need a network round-trip).
        static::saving(function (self $point) {
            if (! $point->map_url) {
                return;
            }
            if ($point->isDirty('latitude') || $point->isDirty('longitude')) {
                return;
            }
            if ($coords = GoogleMapsUrl::parse($point->map_url)) {
                $point->latitude = $coords['lat'];
                $point->longitude = $coords['lng'];
            }
        });

        static::saved(function (self $point) {
            if (! $point->map_url || ! GoogleMapsUrl::isShortLink($point->map_url)) {
                return;
            }
            // Resolve when we still have no coordinates, or the link just
            // changed to a new short link we couldn't read inline.
            if ($point->latitude === null || $point->wasChanged('map_url')) {
                ResolvePickupPointCoordinates::dispatch($point->id);
            }
        });
    }
}
