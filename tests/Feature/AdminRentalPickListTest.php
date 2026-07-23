<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ใบรวมอุปกรณ์เช่าต่อรอบเดินทาง — เดิมต้องเปิดใบจองทีละใบมานับเอง
 */
class AdminRentalPickListTest extends TestCase
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

    private function makeSchedule(int $daysFromNow = 5): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ดอยม่อนจอง', 'slug' => 'rent-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3900, 'status' => 'active',
            'rental_items' => [
                ['name' => 'ถุงนอน', 'price' => 200],
                ['name' => 'เต็นท์ 2 คน', 'price' => 350],
            ],
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysFromNow)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysFromNow + 1)->toDateString(),
            'total_seats' => 12, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function bookWithRentals(TripSchedule $schedule, array $rentals, string $status = 'confirmed'): Booking
    {
        $customer = User::factory()->create(['phone' => '0899999999']);
        $customer->assignRole('customer');

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 3900,
            'paid_amount' => 3900,
            'payment_type' => 'full',
            'selected_rentals' => $rentals,
            'rentals_total' => collect($rentals)->sum('total_price'),
        ]);
    }

    public function test_pick_list_totals_each_item_across_every_booking_on_the_round(): void
    {
        $schedule = $this->makeSchedule();

        $this->bookWithRentals($schedule, [
            ['name' => 'ถุงนอน', 'quantity' => 2, 'unit_price' => 200, 'total_price' => 400],
            ['name' => 'เต็นท์ 2 คน', 'quantity' => 1, 'unit_price' => 350, 'total_price' => 350],
        ]);
        $this->bookWithRentals($schedule, [
            ['name' => 'ถุงนอน', 'quantity' => 3, 'unit_price' => 200, 'total_price' => 600],
        ]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/rentals/schedules/{$schedule->id}")
            ->assertOk()
            ->json('data');

        // เรียงจากชิ้นที่ต้องเตรียมมากที่สุด
        $this->assertSame('ถุงนอน', $payload['items'][0]['name']);
        $this->assertSame(5, $payload['items'][0]['quantity']);
        $this->assertSame(2, $payload['items'][0]['renters']);
        $this->assertEquals(1000, $payload['items'][0]['revenue']);

        $this->assertSame('เต็นท์ 2 คน', $payload['items'][1]['name']);
        $this->assertSame(1, $payload['items'][1]['quantity']);

        $this->assertSame(6, $payload['totals']['pieces']);
        $this->assertEquals(1350, $payload['totals']['revenue']);
        $this->assertSame(2, $payload['totals']['bookings']);
    }

    public function test_pick_list_breaks_down_who_rented_what_for_handing_gear_over(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->bookWithRentals($schedule, [
            ['name' => 'ถุงนอน', 'quantity' => 1, 'unit_price' => 200, 'total_price' => 200],
        ]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/rentals/schedules/{$schedule->id}")
            ->assertOk()
            ->json('data');

        $row = $payload['bookings'][0];
        $this->assertSame($booking->booking_ref, $row['booking_ref']);
        $this->assertSame('0899999999', $row['phone']);
        $this->assertSame('ถุงนอน', $row['items'][0]['name']);
    }

    public function test_cancelled_bookings_are_not_packed_for(): void
    {
        $schedule = $this->makeSchedule();
        $this->bookWithRentals($schedule, [
            ['name' => 'ถุงนอน', 'quantity' => 4, 'unit_price' => 200, 'total_price' => 800],
        ], status: 'cancelled');

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/rentals/schedules/{$schedule->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $payload['totals']['pieces']);
        $this->assertSame([], $payload['items']);
    }

    public function test_schedule_list_shows_only_upcoming_rounds_that_have_rentals(): void
    {
        $upcoming = $this->makeSchedule(daysFromNow: 4);
        $this->bookWithRentals($upcoming, [
            ['name' => 'ถุงนอน', 'quantity' => 1, 'unit_price' => 200, 'total_price' => 200],
        ]);

        $past = $this->makeSchedule(daysFromNow: -10);
        $this->bookWithRentals($past, [
            ['name' => 'เต็นท์ 2 คน', 'quantity' => 1, 'unit_price' => 350, 'total_price' => 350],
        ]);

        // รอบที่ไม่มีใครเช่าของ ต้องไม่โผล่มารกรายการ
        $this->makeSchedule(daysFromNow: 6);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/rentals/schedules')
            ->assertOk();

        $this->assertCount(1, $res->json('data.schedules'));
        $this->assertSame($upcoming->id, $res->json('data.schedules.0.id'));
        $this->assertEquals(200, $res->json('data.schedules.0.rentals_revenue'));

        // เปิดดูย้อนหลังได้เมื่อขอ
        $withPast = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/rentals/schedules?include_past=1')
            ->assertOk();

        $this->assertCount(2, $withPast->json('data.schedules'));
    }

    public function test_customers_cannot_read_the_rental_pick_list(): void
    {
        $schedule = $this->makeSchedule();
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/admin/rentals/schedules/{$schedule->id}")
            ->assertForbidden();
    }
}
