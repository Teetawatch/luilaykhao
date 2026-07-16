<?php

namespace App\Jobs;

use App\Models\SchedulePickupPoint;
use App\Support\GoogleMapsUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Expands a pickup point's short Google Maps link into real coordinates in the
 * background, so saving the point never blocks on an outbound HTTP request.
 */
class ResolvePickupPointCoordinates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $pointId) {}

    public function handle(): void
    {
        $point = SchedulePickupPoint::find($this->pointId);

        if (! $point || ! $point->map_url) {
            return;
        }

        $coords = GoogleMapsUrl::resolve($point->map_url);

        if (! $coords) {
            return;
        }

        // saveQuietly so we don't re-enter the model's saved hook.
        $point->latitude = $coords['lat'];
        $point->longitude = $coords['lng'];
        $point->saveQuietly();
    }
}
