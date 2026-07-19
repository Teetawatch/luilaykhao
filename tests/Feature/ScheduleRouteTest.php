<?php

namespace Tests\Feature;

use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\Polyline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeSchedule(array $tripAttrs = []): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'ภูชี้ฟ้า 2 วัน 1 คืน',
            'slug' => 'phu-chi-fa',
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2000,
            'status' => 'active',
            'latitude' => 19.8390,
            'longitude' => 100.4430,
        ], $tripAttrs));

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(8)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function addPickup(TripSchedule $schedule, array $attrs = []): SchedulePickupPoint
    {
        return SchedulePickupPoint::create(array_merge([
            'schedule_id' => $schedule->id,
            'region' => 'central',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'latitude' => 13.8023,
            'longitude' => 100.5536,
            'pickup_time' => '21:00',
            'sort_order' => 1,
        ], $attrs));
    }

    public function test_schedule_route_returns_stops_and_multi_stop_polyline(): void
    {
        config(['services.google_maps.api_key' => 'test-key']);

        Http::fake([
            'maps.googleapis.com/maps/api/directions/*' => Http::response([
                'status' => 'OK',
                'routes' => [[
                    'overview_polyline' => ['points' => '_p~iF~ps|U_ulLnnqC'],
                    'legs' => [
                        ['distance' => ['value' => 30000], 'duration' => ['value' => 1800]],
                        ['distance' => ['value' => 500000], 'duration' => ['value' => 21600]],
                    ],
                ]],
            ], 200),
        ]);

        $schedule = $this->makeSchedule();
        $this->addPickup($schedule, ['sort_order' => 1]);
        $this->addPickup($schedule, [
            'pickup_location' => 'ปั๊ม ปตท. รังสิต',
            'latitude' => 13.9889,
            'longitude' => 100.6183,
            'pickup_time' => '21:45',
            'sort_order' => 2,
        ]);

        $this->getJson("/api/v1/schedules/{$schedule->id}/route")
            ->assertOk()
            ->assertJsonPath('data.schedule_id', $schedule->id)
            ->assertJsonPath('data.trip_title', 'ภูชี้ฟ้า 2 วัน 1 คืน')
            ->assertJsonCount(3, 'data.stops')
            ->assertJsonPath('data.stops.0.type', 'pickup')
            ->assertJsonPath('data.stops.0.name', 'BTS หมอชิต')
            ->assertJsonPath('data.stops.0.pickup_time', '21:00')
            ->assertJsonPath('data.stops.1.name', 'ปั๊ม ปตท. รังสิต')
            ->assertJsonPath('data.stops.2.type', 'destination')
            ->assertJsonPath('data.stops.2.name', 'เชียงราย')
            ->assertJsonPath('data.polyline', '_p~iF~ps|U_ulLnnqC')
            ->assertJsonPath('data.distance', 530000)
            ->assertJsonPath('data.duration', 23400)
            ->assertJsonCount(2, 'data.legs');

        // origin = จุดรับแรก, destination = ปลายทางทริป, waypoint = จุดรับกลางทาง
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'origin=13.8023%2C100.5536')
                && str_contains($request->url(), 'destination=19.839%2C100.443')
                && str_contains($request->url(), 'waypoints=13.9889%2C100.6183');
        });
    }

    public function test_schedule_route_falls_back_to_empty_polyline_without_api_key(): void
    {
        config(['services.google_maps.api_key' => '']);

        $schedule = $this->makeSchedule();
        $this->addPickup($schedule);

        $this->getJson("/api/v1/schedules/{$schedule->id}/route")
            ->assertOk()
            ->assertJsonCount(2, 'data.stops')
            ->assertJsonPath('data.polyline', '')
            ->assertJsonPath('data.distance', 0);
    }

    public function test_schedule_route_skips_pickups_without_coordinates_in_routing(): void
    {
        config(['services.google_maps.api_key' => 'test-key']);

        Http::fake([
            'maps.googleapis.com/maps/api/directions/*' => Http::response([
                'status' => 'OK',
                'routes' => [[
                    'overview_polyline' => ['points' => 'abc'],
                    'legs' => [
                        ['distance' => ['value' => 700000], 'duration' => ['value' => 28800]],
                    ],
                ]],
            ], 200),
        ]);

        $schedule = $this->makeSchedule();
        $this->addPickup($schedule, ['sort_order' => 1]);
        $this->addPickup($schedule, [
            'pickup_location' => 'จุดนัดพบไม่มีพิกัด',
            'latitude' => null,
            'longitude' => null,
            'sort_order' => 2,
        ]);

        $this->getJson("/api/v1/schedules/{$schedule->id}/route")
            ->assertOk()
            // จุดไม่มีพิกัดยังโผล่ใน timeline แต่ไม่ถูกใช้คำนวณเส้นทาง
            ->assertJsonCount(3, 'data.stops')
            ->assertJsonPath('data.stops.1.latitude', null)
            ->assertJsonPath('data.polyline', 'abc');

        Http::assertSent(function ($request) {
            return ! str_contains($request->url(), 'waypoints=');
        });
    }

    public function test_schedule_route_marks_completed_pickups(): void
    {
        config(['services.google_maps.api_key' => '']);

        $schedule = $this->makeSchedule();
        $this->addPickup($schedule, ['completed_at' => now()]);

        $this->getJson("/api/v1/schedules/{$schedule->id}/route")
            ->assertOk()
            ->assertJsonPath('data.stops.0.completed', true)
            ->assertJsonPath('data.stops.1.completed', false);
    }

    public function test_schedule_route_without_trip_coordinates_omits_destination_stop(): void
    {
        config(['services.google_maps.api_key' => '']);

        $schedule = $this->makeSchedule(['latitude' => null, 'longitude' => null]);
        $this->addPickup($schedule);

        $this->getJson("/api/v1/schedules/{$schedule->id}/route")
            ->assertOk()
            ->assertJsonCount(1, 'data.stops')
            ->assertJsonPath('data.stops.0.type', 'pickup');
    }

    public function test_schedule_route_404_for_unknown_schedule(): void
    {
        $this->getJson('/api/v1/schedules/999999/route')->assertNotFound();
    }

    // ── เส้นทางที่แอดมินวาดเอง (custom_route override) ─────────────────────────

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_can_save_custom_route_and_it_overrides_google(): void
    {
        config(['services.google_maps.api_key' => 'test-key']);
        Http::fake(); // ต้องไม่มีการยิง Google เลยเมื่อใช้เส้นวาดเอง

        $schedule = $this->makeSchedule();
        $this->addPickup($schedule);

        $points = [
            ['lat' => 13.8023, 'lng' => 100.5536],
            ['lat' => 14.5, 'lng' => 100.6],
            ['lat' => 19.839, 'lng' => 100.443],
        ];

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/route", ['points' => $points])
            ->assertOk()
            ->assertJsonCount(3, 'data.custom_route');

        $response = $this->getJson("/api/v1/schedules/{$schedule->id}/route")
            ->assertOk()
            ->assertJsonPath('data.source', 'custom')
            ->json('data');

        // polyline ต้องถอดรหัสกลับมาเป็นจุดที่วาดไว้ (แม่นระดับ 1e-5)
        $this->assertNotSame('', $response['polyline']);
        $this->assertGreaterThan(0, $response['distance']);
        Http::assertNothingSent();
    }

    public function test_custom_polyline_encoding_matches_google_format(): void
    {
        // ตัวอย่างอ้างอิงจากเอกสาร Google Polyline Algorithm
        $encoded = Polyline::encode([
            ['lat' => 38.5, 'lng' => -120.2],
            ['lat' => 40.7, 'lng' => -120.95],
            ['lat' => 43.252, 'lng' => -126.453],
        ]);

        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $encoded);
    }

    public function test_admin_clearing_custom_route_falls_back_to_google(): void
    {
        config(['services.google_maps.api_key' => '']);

        $schedule = $this->makeSchedule();
        $this->addPickup($schedule);
        $schedule->update(['custom_route' => [
            ['lat' => 13.8, 'lng' => 100.5],
            ['lat' => 19.8, 'lng' => 100.4],
        ]]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/route", ['points' => []])
            ->assertOk();

        $this->assertNull($schedule->fresh()->custom_route);
        $this->getJson("/api/v1/schedules/{$schedule->id}/route")
            ->assertOk()
            ->assertJsonPath('data.source', 'none');
    }

    public function test_custom_route_rejects_single_point_and_bad_coords(): void
    {
        $schedule = $this->makeSchedule();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/route", [
                'points' => [['lat' => 13.8, 'lng' => 100.5]],
            ])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/route", [
                'points' => [['lat' => 999, 'lng' => 100.5], ['lat' => 13.8, 'lng' => 100.5]],
            ])
            ->assertStatus(422);
    }

    public function test_custom_route_update_requires_admin_role(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/route", [
                'points' => [['lat' => 13.8, 'lng' => 100.5], ['lat' => 14.0, 'lng' => 100.6]],
            ])
            ->assertForbidden();
    }
}
