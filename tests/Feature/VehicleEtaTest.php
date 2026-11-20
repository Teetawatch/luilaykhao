<?php

namespace Tests\Feature;

use App\Events\VehicleEtaUpdated;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ETA ร่วมของรถ: Google ถูกถามครั้งเดียวต่อรถทุก 3–5 นาที ไม่ใช่ครั้งต่อลูกค้าต่อ 25 วิ
 * และเงียบเองเมื่อไม่มีใครต้องรอแล้ว
 */
class VehicleEtaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Event::fake([VehicleEtaUpdated::class]);
        config(['services.google_maps.api_key' => 'server-key']);
        $this->fakeRoutes();
    }

    public function test_one_google_call_serves_every_waiting_customer(): void
    {
        [$schedule, $first, $second] = $this->scenario();

        $this->artisan('tracking:refresh-eta')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'routes.googleapis.com/directions/v2:computeRoutes')
                && $request->hasHeader('X-Goog-Api-Key', 'server-key')
                && $request['routingPreference'] === 'TRAFFIC_AWARE'
                && count($request['intermediates']) === 1
                // field mask ต้องไม่ขอข้อมูลที่ดัน SKU ขึ้น Enterprise
                && ! str_contains($request->header('X-Goog-FieldMask')[0], 'toll');
        });

        // ลูกค้ากี่คนเปิดจอก็อ่านจากแคช ไม่ถาม Google เพิ่ม
        foreach (range(1, 5) as $_) {
            $this->getJson("/api/v1/tracking/schedules/{$schedule->id}/eta")
                ->assertOk()
                ->assertJsonPath('data.active', true)
                ->assertJsonPath('data.source', 'google')
                ->assertJsonPath('data.stops.0.pickup_point_id', $first->id)
                ->assertJsonPath('data.stops.0.eta_minutes', 10)
                ->assertJsonPath('data.stops.0.polyline', 'leg-one')
                // จุดที่สองนับเวลาจอดรับที่จุดแรกด้วย (600 + 900 + 180 วิ)
                ->assertJsonPath('data.stops.1.pickup_point_id', $second->id)
                ->assertJsonPath('data.stops.1.eta_minutes', 28);
        }
        Http::assertSentCount(1);

        // ส่งให้แอปผ่าน Reverb ไม่ต้องรอถาม — แต่ไม่แบกเส้นทางไปด้วย
        Event::assertDispatched(VehicleEtaUpdated::class, fn ($e) => $e->vehicleId === $schedule->vehicle_id
            && $e->payload['stops'][0]['eta_minutes'] === 10
            && ! isset($e->payload['stops'][0]['polyline']));

        $this->assertSame(1, (int) DB::table('maps_api_usage')->where('sku', 'routes_pro')->value('units'));
    }

    public function test_it_asks_again_only_after_the_interval_and_only_if_the_van_moved(): void
    {
        [$schedule] = $this->scenario();

        $this->artisan('tracking:refresh-eta');
        // ทุกนาทีถัดมาไม่ถามซ้ำ
        $this->travel(2)->minutes();
        $this->pin($schedule, 13.8000, 100.5501);
        $this->artisan('tracking:refresh-eta');
        Http::assertSentCount(1);

        // ครบ 4 นาทีแต่รถจอดนิ่ง (GPS แกว่งไม่กี่เมตร) — แค่เลื่อนเวลาถึง ไม่เสียเงิน
        $this->travel(3)->minutes();
        $this->pin($schedule, 13.8002, 100.5500);
        $this->artisan('tracking:refresh-eta');
        Http::assertSentCount(1);
        $this->getJson("/api/v1/tracking/schedules/{$schedule->id}/eta")
            ->assertJsonPath('data.stops.0.eta_minutes', 10);

        // รถวิ่งไปแล้วเกิน 150 ม. และครบรอบ — ถามใหม่
        $this->travel(4)->minutes();
        $this->pin($schedule, 13.8300, 100.5800);
        $this->artisan('tracking:refresh-eta');
        Http::assertSentCount(2);
    }

    public function test_it_stops_once_everyone_is_picked_up(): void
    {
        [$schedule, $first, $second] = $this->scenario();
        $this->artisan('tracking:refresh-eta');

        $first->update(['completed_at' => now()]);
        $second->update(['completed_at' => now()]);
        $this->travel(5)->minutes();
        $this->pin($schedule, 13.9000, 100.6000);

        $this->artisan('tracking:refresh-eta');
        $this->travel(5)->minutes();
        $this->artisan('tracking:refresh-eta');

        Http::assertSentCount(1);
        $this->getJson("/api/v1/tracking/schedules/{$schedule->id}/eta")
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.reason', 'all_picked_up');
        // ประกาศหยุดครั้งเดียว ไม่ใช่ทุกนาที
        Event::assertDispatchedTimes(VehicleEtaUpdated::class, 2);
    }

    public function test_a_finished_trip_or_a_silent_van_costs_nothing(): void
    {
        [$schedule] = $this->scenario(pin: false);

        // พิกัดล่าสุดเงียบไปครึ่งชั่วโมง = ไม่มีสัญญาณ
        $this->pin($schedule, 13.80, 100.55, minutesAgo: 30);
        $this->artisan('tracking:refresh-eta');
        $this->getJson("/api/v1/tracking/schedules/{$schedule->id}/eta")
            ->assertJsonPath('data.reason', 'no_signal');

        $schedule->update(['status' => 'completed']);
        $this->pin($schedule, 13.80, 100.55);
        $this->artisan('tracking:refresh-eta');

        Http::assertNothingSent();
    }

    public function test_the_daily_cap_falls_back_to_an_estimate_instead_of_paying(): void
    {
        config(['maps.caps.routes_pro.daily' => 1]);
        [$schedule] = $this->scenario();

        $this->artisan('tracking:refresh-eta');
        $this->travel(5)->minutes();
        $this->pin($schedule, 13.8500, 100.6000);
        $this->artisan('tracking:refresh-eta');

        Http::assertSentCount(1);
        $usage = DB::table('maps_api_usage')->where('sku', 'routes_pro')->first();
        $this->assertSame(1, (int) $usage->units);
        $this->assertSame(1, (int) $usage->blocked);
        // ยังมี ETA ให้ลูกค้า (จากของเดิม/เส้นตรง) ไม่ใช่จอว่าง
        $this->getJson("/api/v1/tracking/schedules/{$schedule->id}/eta")
            ->assertJsonPath('data.active', true);
    }

    public function test_without_a_key_it_estimates_from_a_straight_line(): void
    {
        config(['services.google_maps.api_key' => '']);
        [$schedule] = $this->scenario();

        $this->artisan('tracking:refresh-eta');

        Http::assertNothingSent();
        $this->getJson("/api/v1/tracking/schedules/{$schedule->id}/eta")
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.source', 'estimate')
            ->assertJsonPath('data.stops.0.polyline', '');
    }

    public function test_the_approaching_push_uses_the_shared_eta(): void
    {
        [$schedule, $first, , $customer] = $this->scenario();
        // Google: 10 นาที (รถติด) — เส้นตรงจากพิกัดนี้คิดได้ราว 20+ นาที ซึ่งไม่ถึงเกณฑ์ "ใกล้ถึง"
        $this->artisan('tracking:refresh-eta');
        $this->artisan('eta:notify-pickups');

        $push = SmartNotification::where('user_id', $customer->id)->where('type', 'vehicle_approaching')->first();
        $this->assertNotNull($push);
        $this->assertSame(10, $push->data['eta_minutes']);
    }

    public function test_admin_sees_usage_against_the_free_cap(): void
    {
        [$schedule] = $this->scenario();
        $this->artisan('tracking:refresh-eta');

        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/tracking/maps-usage')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.eta_sessions.0.schedule_id', $schedule->id)
            ->assertJsonPath('data.eta_sessions.0.active', true);

        $pro = collect($response->json('data.skus'))->firstWhere('sku', 'routes_pro');
        $this->assertSame(1, $pro['units']);
        $this->assertSame(5000, $pro['free_cap']);
        // ยังอยู่ในโควตาฟรี
        $this->assertEquals(0, $pro['estimated_usd']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/tracking/maps-usage')
            ->assertForbidden();
    }

    public function test_the_booking_tracking_payload_names_its_pickup_point(): void
    {
        [, $first, , $customer, $booking] = $this->scenario();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/tracking")
            ->assertOk()
            ->assertJsonPath('data.pickup_point_id', $first->id);
    }

    private function fakeRoutes(): void
    {
        Http::fake([
            'routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'distanceMeters' => 9000,
                    'duration' => '1500s',
                    'staticDuration' => '1200s',
                    'polyline' => ['encodedPolyline' => 'whole'],
                    'legs' => [
                        ['distanceMeters' => 4000, 'duration' => '600s', 'staticDuration' => '500s', 'polyline' => ['encodedPolyline' => 'leg-one']],
                        ['distanceMeters' => 5000, 'duration' => '900s', 'staticDuration' => '700s', 'polyline' => ['encodedPolyline' => 'leg-two']],
                    ],
                ]],
            ]),
        ]);
    }

    private function pin(TripSchedule $schedule, float $lat, float $lng, int $minutesAgo = 0): void
    {
        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'latitude' => $lat,
            'longitude' => $lng,
            'speed' => 30,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * @return array{0: TripSchedule, 1: SchedulePickupPoint, 2: SchedulePickupPoint, 3: User, 4: Booking}
     */
    private function scenario(bool $pin = true): array
    {
        $trip = Trip::create([
            'title' => 'ภูชี้ฟ้า',
            'slug' => 'eta-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'ฮก 1234',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $first = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'หมอชิต',
            'latitude' => 13.9200,
            'longitude' => 100.6500,
            'price' => 0,
            'sort_order' => 1,
        ]);
        $second = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'รังสิต',
            'latitude' => 13.9889,
            'longitude' => 100.6183,
            'price' => 0,
            'sort_order' => 2,
        ]);

        $customer = User::factory()->create();
        $booking = null;
        foreach ([$first, $second] as $i => $point) {
            $made = Booking::create([
                'booking_ref' => Booking::generateRef(),
                'user_id' => $i === 0 ? $customer->id : User::factory()->create()->id,
                'schedule_id' => $schedule->id,
                'status' => 'confirmed',
                'qr_code' => Booking::generateQrCode(),
                'pickup_point_id' => $point->id,
                'total_amount' => 2500,
                'paid_amount' => 2500,
            ]);
            BookingPassenger::create([
                'booking_id' => $made->id,
                'name' => 'ผู้โดยสาร '.$i,
                'phone' => '080000000'.$i,
                'pickup_point_id' => $point->id,
            ]);
            $booking ??= $made;
        }

        if ($pin) {
            $this->pin($schedule, 13.8000, 100.5500);
        }

        return [$schedule, $first, $second, $customer, $booking];
    }
}
