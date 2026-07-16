<?php

namespace App\Console\Commands;

use App\Models\SchedulePickupPoint;
use App\Support\GoogleMapsUrl;
use Illuminate\Console\Command;

/**
 * Backfills latitude/longitude for pickup points that already have a Google
 * Maps URL but no coordinates (including short links, resolved over HTTP).
 */
class GeocodePickupPoints extends Command
{
    protected $signature = 'pickups:geocode {--all : Re-resolve even points that already have coordinates}';

    protected $description = 'Derive lat/lng for schedule pickup points from their Google Maps URL';

    public function handle(): int
    {
        $query = SchedulePickupPoint::query()
            ->whereNotNull('map_url')
            ->where('map_url', '!=', '');

        if (! $this->option('all')) {
            $query->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'));
        }

        $points = $query->get();

        if ($points->isEmpty()) {
            $this->info('No pickup points need geocoding.');

            return self::SUCCESS;
        }

        $resolved = 0;
        $failed = 0;

        foreach ($points as $point) {
            $coords = GoogleMapsUrl::resolve($point->map_url);

            if ($coords) {
                $point->latitude = $coords['lat'];
                $point->longitude = $coords['lng'];
                $point->saveQuietly();
                $resolved++;
                $this->line("  ✓ #{$point->id} {$point->pickup_location} → {$coords['lat']}, {$coords['lng']}");
            } else {
                $failed++;
                $this->warn("  ✗ #{$point->id} {$point->pickup_location} — could not read coordinates from {$point->map_url}");
            }
        }

        $this->info("Done. Resolved {$resolved}, could not resolve {$failed}.");

        return self::SUCCESS;
    }
}
