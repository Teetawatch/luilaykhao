<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TripMapTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูกระดึง',
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'northeast',
            'difficulty' => 'medium',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 3900,
            'latitude' => 16.86,
            'longitude' => 101.79,
            'status' => 'active',
        ], $overrides));
    }

    private function makeSchedule(Trip $trip, string $departure, ?float $priceOverride = null, string $status = 'open'): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => $status,
            'price_override' => $priceOverride,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('trips-map');
    }

    public function test_returns_only_trips_that_can_be_pinned(): void
    {
        $this->makeTrip(['title' => 'มีพิกัด']);
        $this->makeTrip(['title' => 'ไม่มีพิกัด', 'latitude' => null, 'longitude' => null]);
        $this->makeTrip(['title' => 'ปิดอยู่', 'status' => 'inactive']);

        $response = $this->getJson('/api/v1/trips/map')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.title', 'มีพิกัด');
    }

    public function test_price_from_uses_the_cheapest_bookable_upcoming_round(): void
    {
        $trip = $this->makeTrip();
        $next = now('Asia/Bangkok')->addDays(10)->toDateString();

        $this->makeSchedule($trip, $next, 3500);
        $this->makeSchedule($trip, now('Asia/Bangkok')->addDays(30)->toDateString(), 2900);
        // รอบที่ปิดขายและรอบที่ผ่านไปแล้วต้องไม่ถูกนับเป็นราคาเริ่มต้น
        $this->makeSchedule($trip, now('Asia/Bangkok')->addDays(20)->toDateString(), 900, 'closed');
        $this->makeSchedule($trip, now('Asia/Bangkok')->subDays(5)->toDateString(), 500);

        $this->getJson('/api/v1/trips/map')
            ->assertOk()
            ->assertJsonPath('data.0.price_from', 2900)
            ->assertJsonPath('data.0.upcoming_count', 2)
            ->assertJsonPath('data.0.next_departure', $next);
    }

    public function test_price_falls_back_to_the_trip_price_without_open_rounds(): void
    {
        $this->makeTrip();

        $this->getJson('/api/v1/trips/map')
            ->assertOk()
            ->assertJsonPath('data.0.price_from', 3900)
            ->assertJsonPath('data.0.upcoming_count', 0)
            ->assertJsonPath('data.0.next_departure', null);
    }

    public function test_months_list_drives_the_month_filter(): void
    {
        $trip = $this->makeTrip();
        $first = now('Asia/Bangkok')->addDays(10);
        $second = now('Asia/Bangkok')->addMonths(2);

        $this->makeSchedule($trip, $first->toDateString());
        $this->makeSchedule($trip, $second->toDateString());

        $months = $this->getJson('/api/v1/trips/map')->assertOk()->json('data.0.months');

        $this->assertEqualsCanonicalizing(
            array_unique([(int) $first->format('n'), (int) $second->format('n')]),
            $months,
        );
    }
}
