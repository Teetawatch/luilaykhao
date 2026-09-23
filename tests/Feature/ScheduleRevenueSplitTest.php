<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หน้าภาพรวมรอบต้องแยกเงินที่ลูกค้าจ่ายเป็นค่าทริป / ของเสริม / ค่าเช่าอุปกรณ์
 *
 * ค่าเช่าอุปกรณ์ไม่มีต้นทุนต่อรอบ (ของเป็นของบริษัทอยู่แล้ว) ผู้จัดจึงต้องอ่าน
 * ยอดก้อนนี้แยกออกมาได้ทันที ไม่ต้องไปหักออกจากยอดรวมเอง
 */
class ScheduleRevenueSplitTest extends TestCase
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

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ดอยม่อนจอง',
            'slug' => 'split-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'max_participants' => 12,
            'price_per_person' => 3900,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(12)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function book(TripSchedule $schedule, string $name, array $attributes): Booking
    {
        $customer = User::factory()->create(['name' => $name, 'phone' => '0899999999']);
        $customer->assignRole('customer');

        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'payment_type' => 'full',
        ], $attributes));

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'คุณ',
            'name' => $name,
            'phone' => '0899999999',
        ]);

        return $booking;
    }

    public function test_calendar_payload_splits_trip_addons_and_rental_money(): void
    {
        $schedule = $this->makeSchedule();

        // 3,900 ค่าทริป + 300 เสื้อ + 400 ค่าเช่า
        $this->book($schedule, 'สมชาย', [
            'total_amount' => 4600,
            'paid_amount' => 4600,
            'addons_total' => 300,
            'selected_addons' => [
                ['name' => 'เสื้อทริป', 'price_type' => 'per_person', 'unit_price' => 300, 'quantity' => 1, 'total_price' => 300],
            ],
            'rentals_total' => 400,
            'selected_rentals' => [
                ['key' => 'sleeping-bag', 'name' => 'ถุงนอน', 'unit_price' => 200, 'quantity' => 2, 'total_price' => 400],
            ],
        ]);

        // 3,900 ค่าทริป + 350 ค่าเช่า (ไม่มีของเสริม)
        $this->book($schedule, 'มาลี', [
            'total_amount' => 4250,
            'paid_amount' => 2000,
            'addons_total' => 0,
            'rentals_total' => 350,
            'selected_rentals' => [
                ['key' => 'tent2', 'name' => 'เต็นท์ 2 คน', 'unit_price' => 350, 'quantity' => 1, 'total_price' => 350],
            ],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk();

        $response->assertJsonPath('data.0.total_amount', 8850)
            ->assertJsonPath('data.0.addons_total_amount', 300)
            ->assertJsonPath('data.0.rentals_total_amount', 750)
            ->assertJsonPath('data.0.trip_total_amount', 7800);
    }

    public function test_rentals_summary_groups_each_item_with_its_customers(): void
    {
        $schedule = $this->makeSchedule();

        $first = $this->book($schedule, 'สมชาย', [
            'total_amount' => 4300,
            'paid_amount' => 4300,
            'rentals_total' => 400,
            'selected_rentals' => [
                ['key' => 'sleeping-bag', 'name' => 'ถุงนอน', 'unit_price' => 200, 'quantity' => 2, 'total_price' => 400],
            ],
        ]);

        $second = $this->book($schedule, 'มาลี', [
            'total_amount' => 4100,
            'paid_amount' => 4100,
            'rentals_total' => 200,
            'selected_rentals' => [
                // ชื่อในแคตตาล็อกถูกแก้ทีหลัง แต่ key เดิม — ต้องยังนับเป็นของชิ้นเดียวกัน
                ['key' => 'sleeping-bag', 'name' => 'ถุงนอน (ใหม่)', 'unit_price' => 200, 'quantity' => 1, 'total_price' => 200],
            ],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk();

        $response->assertJsonCount(1, 'data.0.rentals_summary')
            ->assertJsonPath('data.0.rentals_summary.0.key', 'sleeping-bag')
            ->assertJsonPath('data.0.rentals_summary.0.total_quantity', 3)
            ->assertJsonPath('data.0.rentals_summary.0.total_price', 600)
            ->assertJsonCount(2, 'data.0.rentals_summary.0.customers')
            ->assertJsonPath('data.0.rentals_summary.0.customers.0.booking_ref', $first->booking_ref)
            ->assertJsonPath('data.0.rentals_summary.0.customers.0.quantity', 2)
            ->assertJsonPath('data.0.rentals_summary.0.customers.1.booking_ref', $second->booking_ref)
            ->assertJsonPath('data.0.rentals_summary.0.customers.1.name', 'มาลี');
    }

    public function test_manifest_rows_carry_the_split_for_their_booking(): void
    {
        $schedule = $this->makeSchedule();

        $this->book($schedule, 'สมชาย', [
            'total_amount' => 4600,
            'paid_amount' => 4600,
            'addons_total' => 300,
            'rentals_total' => 400,
            'selected_rentals' => [
                ['key' => 'sleeping-bag', 'name' => 'ถุงนอน', 'unit_price' => 200, 'quantity' => 2, 'total_price' => 400],
            ],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.passenger_manifest.0.addons_total', 300)
            ->assertJsonPath('data.0.passenger_manifest.0.rentals_total', 400)
            ->assertJsonPath('data.0.passenger_manifest.0.trip_amount', 3900);
    }

    public function test_payment_breakdown_lists_the_rented_items_per_booking(): void
    {
        $schedule = $this->makeSchedule();

        $this->book($schedule, 'สมชาย', [
            'total_amount' => 4600,
            'paid_amount' => 4600,
            'addons_total' => 300,
            'rentals_total' => 400,
            'selected_rentals' => [
                ['key' => 'sleeping-bag', 'name' => 'ถุงนอน', 'unit_price' => 200, 'quantity' => 2, 'total_price' => 400],
            ],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/payments")
            ->assertOk()
            ->assertJsonPath('data.0.trip_amount', 3900)
            ->assertJsonPath('data.0.addons_total', 300)
            ->assertJsonPath('data.0.rentals_total', 400)
            ->assertJsonCount(1, 'data.0.rentals')
            ->assertJsonPath('data.0.rentals.0.name', 'ถุงนอน')
            ->assertJsonPath('data.0.rentals.0.quantity', 2)
            ->assertJsonPath('data.0.rentals.0.total_price', 400);
    }
}
