<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConquestMapTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Doi '.uniqid(),
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Rai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'distance_km' => 20,
            'elevation_gain_m' => 1000,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
            'latitude' => 19.9,
            'longitude' => 99.2,
        ], $overrides));
    }

    private function book(User $user, Trip $trip, ?string $departure = null, string $status = 'completed'): Booking
    {
        $departure = $departure ?? now('Asia/Bangkok')->subDays(20)->toDateString();

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => sprintf('LLK-20200101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    public function test_map_returns_pins_and_region_depth_for_completed_trips(): void
    {
        $user = User::factory()->create();

        $north = $this->makeTrip(['region' => 'north', 'distance_km' => 20, 'elevation_gain_m' => 1200]);
        $south = $this->makeTrip(['region' => 'south', 'distance_km' => 8, 'elevation_gain_m' => 300]);

        $this->book($user, $north, now('Asia/Bangkok')->subDays(60)->toDateString());
        $this->book($user, $south, now('Asia/Bangkok')->subDays(10)->toDateString());

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport/map')
            ->assertOk()
            ->assertJsonPath('data.summary.regions_visited', 2)
            ->assertJsonPath('data.summary.regions_total', 7)
            ->assertJsonPath('data.summary.trips_visited', 2)
            ->assertJsonPath('data.summary.total_distance_km', 28)
            ->assertJsonPath('data.summary.total_elevation_gain_m', 1500)
            ->assertJsonPath('data.summary.toughest.elevation_gain_m', 1200);

        $regions = collect($response->json('data.regions'));
        $this->assertCount(7, $regions, 'every region is returned so the app can draw the unvisited ones');

        $northRegion = $regions->firstWhere('key', 'north');
        $this->assertTrue($northRegion['visited']);
        $this->assertSame(1, $northRegion['trips_count']);
        $this->assertSame(1200, $northRegion['elevation_gain_m']);

        $east = $regions->firstWhere('key', 'east');
        $this->assertFalse($east['visited']);
        $this->assertSame(0, $east['trips_count']);
        $this->assertNull($east['first_visit']);

        $this->assertCount(2, $response->json('data.pins'));
    }

    public function test_repeat_visits_collapse_to_one_pin_but_still_count(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip(['distance_km' => 12, 'elevation_gain_m' => 500]);

        $this->book($user, $trip, now('Asia/Bangkok')->subDays(90)->toDateString());
        $this->book($user, $trip, now('Asia/Bangkok')->subDays(30)->toDateString());

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport/map')
            ->assertOk()
            ->assertJsonPath('data.summary.trips_visited', 1)
            ->assertJsonPath('data.summary.departures_count', 2)
            // ระยะทางคูณจำนวนครั้งที่ไป — ไปสองรอบก็เดินสองรอบจริง
            ->assertJsonPath('data.summary.total_distance_km', 24);

        $pins = $response->json('data.pins');
        $this->assertCount(1, $pins);
        $this->assertSame(2, $pins[0]['visits']);
        $this->assertSame(now('Asia/Bangkok')->subDays(90)->toDateString(), $pins[0]['first_visit']);
        $this->assertSame(now('Asia/Bangkok')->subDays(30)->toDateString(), $pins[0]['last_visit']);
    }

    public function test_upcoming_trips_are_not_on_the_map_and_frontier_lists_untouched_regions(): void
    {
        $user = User::factory()->create();

        $this->book($user, $this->makeTrip(['region' => 'north']));
        // รอบในอนาคต — ยังไม่ได้ไป ต้องไม่ขึ้นบนแผนที่
        $this->book($user, $this->makeTrip(['region' => 'west']), now('Asia/Bangkok')->addDays(20)->toDateString());
        // ทริปที่เปิดอยู่ในภาคที่ยังไม่เคยไป
        $this->makeTrip(['region' => 'east']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport/map')
            ->assertOk()
            ->assertJsonPath('data.summary.regions_visited', 1);

        $frontier = collect($response->json('data.frontier'));
        $this->assertCount(6, $frontier);
        $this->assertSame(1, $frontier->firstWhere('key', 'east')['open_trips_count']);
        $this->assertNull($frontier->firstWhere('key', 'north'), 'a visited region is not part of the frontier');
    }

    public function test_map_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/passport/map')->assertUnauthorized();
    }
}
