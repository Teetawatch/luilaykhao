<?php

namespace Tests\Feature;

use App\Models\SchedulePickupPoint;
use App\Models\ScheduleVehicleOption;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "ราคาทริปรายเดือน" — ทริป/รอบ/ราคาของเดือนหนึ่งรวมไว้ที่เดียวสำหรับทำสื่อโปรโมท
 */
class AdminMonthlyPriceSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeTrip(string $title, float $price): Trip
    {
        return Trip::create([
            'title' => $title, 'slug' => 'ps-'.uniqid(), 'type' => 'trekking',
            'location' => 'เพชรบูรณ์', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 15, 'price_per_person' => $price, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, array $attributes = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => '2026-09-05',
            'return_date' => '2026-09-06',
            'total_seats' => 15, 'booked_seats' => 3, 'status' => 'open',
            'transport_type' => 'van',
        ], $attributes));
    }

    public function test_it_lists_this_months_trips_with_the_price_each_round_sells_at(): void
    {
        $trip = $this->makeTrip('น้ำตกโกรกอีดก', 1290);
        $this->makeSchedule($trip);
        // รอบเดือนถัดไปต้องไม่หลุดเข้ามา
        $this->makeSchedule($trip, ['departure_date' => '2026-10-03', 'return_date' => '2026-10-04']);

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/price-sheet?month=2026-09');

        $res->assertOk();
        $data = $res->json('data');

        $this->assertSame('2026-09', $data['month']);
        $this->assertSame('กันยายน 2569', $data['month_label']);
        $this->assertCount(1, $data['trips']);
        $this->assertSame('น้ำตกโกรกอีดก', $data['trips'][0]['title']);
        $this->assertCount(1, $data['trips'][0]['schedules']);
        $this->assertEquals(1290.0, $data['trips'][0]['schedules'][0]['price']);
        $this->assertSame('5 – 6 ก.ย.', $data['trips'][0]['schedules'][0]['date_label']);
        $this->assertSame(12, $data['trips'][0]['schedules'][0]['available_seats']);
        $this->assertSame(1, $data['summary']['trip_count']);
        $this->assertSame(1, $data['summary']['schedule_count']);
    }

    public function test_a_round_price_override_wins_over_the_trip_price(): void
    {
        $trip = $this->makeTrip('ภูกระดึง', 2900);
        $this->makeSchedule($trip, ['price_override' => 2500]);

        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/price-sheet?month=2026-09')
            ->json('data');

        $this->assertEquals(2500.0, $data['trips'][0]['schedules'][0]['price']);
        $this->assertEquals(2900.0, $data['trips'][0]['base_price']);
    }

    public function test_pickup_point_prices_come_through_as_full_prices_not_surcharges(): void
    {
        $trip = $this->makeTrip('น้ำตกโกรกอีดก', 1290);
        $schedule = $this->makeSchedule($trip);

        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bangkok', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี', 'price' => 1290, 'sort_order' => 1,
        ]);
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'onsite', 'region_label' => 'หน้างาน',
            'pickup_location' => 'เจอหน้างาน', 'price' => 790, 'sort_order' => 2,
        ]);

        $points = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/price-sheet?month=2026-09')
            ->json('data.trips.0.schedules.0.pickup_points');

        $this->assertCount(2, $points);
        // จุดที่ราคาเท่าราคารอบ = ไม่มีอะไรใหม่ให้เขียนลงรูป
        $this->assertEquals(1290.0, $points[0]['price']);
        $this->assertTrue($points[0]['is_default_price']);
        $this->assertSame('เจอหน้างาน', $points[1]['label']);
        $this->assertEquals(790.0, $points[1]['price']);
        $this->assertFalse($points[1]['is_default_price']);
    }

    public function test_vehicle_option_prices_are_the_round_price_plus_the_per_person_adjustment(): void
    {
        $trip = $this->makeTrip('เกาะเสม็ด', 1500);
        $schedule = $this->makeSchedule($trip);

        ScheduleVehicleOption::create([
            'schedule_id' => $schedule->id, 'label' => 'รถบัส', 'transport_type' => 'bus',
            'price_adjustment' => -200, 'is_active' => true, 'sort_order' => 1,
        ]);
        ScheduleVehicleOption::create([
            'schedule_id' => $schedule->id, 'label' => 'รถตู้ VIP', 'transport_type' => 'van',
            'price_adjustment' => 300, 'is_active' => false, 'sort_order' => 2,
        ]);

        $options = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/price-sheet?month=2026-09')
            ->json('data.trips.0.schedules.0.vehicle_options');

        // คันที่ปิดอยู่ไม่ต้องอยู่ในสื่อโปรโมท
        $this->assertCount(1, $options);
        $this->assertSame('รถบัส', $options[0]['label']);
        $this->assertEquals(1300.0, $options[0]['price']);
    }

    public function test_join_trip_price_is_reported_only_when_the_round_opens_it(): void
    {
        $trip = $this->makeTrip('เขาช้างเผือก', 3200);
        $this->makeSchedule($trip, ['join_trip_enabled' => true, 'join_trip_price' => 1900]);
        $this->makeSchedule($trip, ['departure_date' => '2026-09-19', 'return_date' => '2026-09-20']);

        $schedules = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/price-sheet?month=2026-09')
            ->json('data.trips.0.schedules');

        $this->assertTrue($schedules[0]['join_trip_enabled']);
        $this->assertEquals(1900.0, $schedules[0]['join_trip_price']);
        $this->assertFalse($schedules[1]['join_trip_enabled']);
        $this->assertNull($schedules[1]['join_trip_price']);
    }

    public function test_cancelled_and_charter_rounds_never_reach_the_price_sheet(): void
    {
        $trip = $this->makeTrip('ดอยม่อนจอง', 2400);
        $this->makeSchedule($trip);
        $this->makeSchedule($trip, ['departure_date' => '2026-09-12', 'status' => 'cancelled']);
        $this->makeSchedule($trip, ['departure_date' => '2026-09-26', 'is_charter' => true]);

        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/price-sheet?month=2026-09')
            ->json('data');

        $this->assertSame(1, $data['summary']['schedule_count']);
        $this->assertSame('2026-09-05', $data['trips'][0]['schedules'][0]['departure_date']);
    }

    public function test_a_live_flash_sale_is_the_price_shown_with_the_original_beside_it(): void
    {
        $trip = $this->makeTrip('ปางอุ๋ง', 3500);
        $this->makeSchedule($trip, [
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
            'flash_sale_enabled' => true,
            'flash_sale_price' => 2900,
            'flash_sale_ends_at' => now()->addDays(3),
        ]);

        $month = now('Asia/Bangkok')->addDays(20)->format('Y-m');

        $schedule = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/price-sheet?month={$month}")
            ->json('data.trips.0.schedules.0');

        $this->assertTrue($schedule['on_flash_sale']);
        $this->assertEquals(2900.0, $schedule['price']);
        $this->assertEquals(3500.0, $schedule['original_price']);
    }

    public function test_month_defaults_to_the_current_thai_month(): void
    {
        $trip = $this->makeTrip('เขาสามร้อยยอด', 1800);
        $this->makeSchedule($trip, [
            'departure_date' => now('Asia/Bangkok')->startOfMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->startOfMonth()->toDateString(),
        ]);

        $data = $this->actingAs($this->admin)->getJson('/api/v1/admin/price-sheet')->json('data');

        $this->assertSame(now('Asia/Bangkok')->format('Y-m'), $data['month']);
        $this->assertSame(1, $data['summary']['schedule_count']);
    }

    public function test_it_rejects_a_month_that_is_not_year_dash_month(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/price-sheet?month=2026-09-05')
            ->assertStatus(422);
    }

    public function test_customers_cannot_read_the_price_sheet(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)->getJson('/api/v1/admin/price-sheet')->assertStatus(403);
        $this->getJson('/api/v1/admin/price-sheet')->assertStatus(403);
    }
}
