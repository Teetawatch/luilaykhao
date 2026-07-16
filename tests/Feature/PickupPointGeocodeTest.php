<?php

namespace Tests\Feature;

use App\Jobs\ResolvePickupPointCoordinates;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PickupPointGeocodeTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Geo Trip',
            'slug' => 'geo-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makePoint(TripSchedule $schedule, string $mapUrl): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bkk',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท.',
            'price' => 0,
            'map_url' => $mapUrl,
            'sort_order' => 1,
        ]);
    }

    public function test_coordinates_are_derived_from_full_url_on_create(): void
    {
        Queue::fake();
        $point = $this->makePoint($this->schedule(), 'https://www.google.com/maps/@13.7563,100.5018,17z');

        $this->assertEqualsWithDelta(13.7563, $point->latitude, 0.0000001);
        $this->assertEqualsWithDelta(100.5018, $point->longitude, 0.0000001);
        // A parseable full URL needs no background resolution.
        Queue::assertNotPushed(ResolvePickupPointCoordinates::class);
    }

    public function test_updating_the_map_url_refreshes_coordinates(): void
    {
        Queue::fake();
        $point = $this->makePoint($this->schedule(), 'https://www.google.com/maps/@13.7563,100.5018,17z');

        $point->update(['map_url' => 'https://www.google.com/maps/@18.7883,98.9853,17z']);

        $this->assertEqualsWithDelta(18.7883, $point->fresh()->latitude, 0.0000001);
        $this->assertEqualsWithDelta(98.9853, $point->fresh()->longitude, 0.0000001);
    }

    public function test_explicit_coordinates_are_not_overwritten_by_the_url(): void
    {
        Queue::fake();
        $point = SchedulePickupPoint::create([
            'schedule_id' => $this->schedule()->id,
            'region' => 'bkk',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'จุดรับ',
            'price' => 0,
            'map_url' => 'https://www.google.com/maps/@13.7563,100.5018,17z',
            'latitude' => 15.1234,
            'longitude' => 101.5678,
            'sort_order' => 1,
        ]);

        $this->assertEqualsWithDelta(15.1234, $point->latitude, 0.0000001);
        $this->assertEqualsWithDelta(101.5678, $point->longitude, 0.0000001);
    }

    public function test_short_link_queues_a_resolver_job(): void
    {
        Queue::fake();
        $point = $this->makePoint($this->schedule(), 'https://maps.app.goo.gl/aBcDeF');

        // Cannot be parsed inline, so it stays null until the job resolves it.
        $this->assertNull($point->latitude);
        Queue::assertPushed(ResolvePickupPointCoordinates::class);
    }

    public function test_resolver_job_expands_a_short_link(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response(
                '<html>redirecting to /maps/place/Foo/@13.7563,100.5018,17z</html>'
            ),
        ]);

        $point = $this->makePoint($this->schedule(), 'https://maps.app.goo.gl/aBcDeF');

        (new ResolvePickupPointCoordinates($point->id))->handle();

        $this->assertEqualsWithDelta(13.7563, $point->fresh()->latitude, 0.0000001);
        $this->assertEqualsWithDelta(100.5018, $point->fresh()->longitude, 0.0000001);
    }

    public function test_backfill_command_resolves_missing_coordinates(): void
    {
        Queue::fake(); // keep the create hook from dispatching real work
        $point = $this->makePoint($this->schedule(), 'https://maps.app.goo.gl/aBcDeF');
        $this->assertNull($point->latitude);

        Http::fake([
            'maps.app.goo.gl/*' => Http::response(
                '<html>/maps/place/Foo/@14.0,101.0,17z</html>'
            ),
        ]);

        $this->artisan('pickups:geocode')->assertSuccessful();

        $this->assertEqualsWithDelta(14.0, $point->fresh()->latitude, 0.0000001);
        $this->assertEqualsWithDelta(101.0, $point->fresh()->longitude, 0.0000001);
    }
}
