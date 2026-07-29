<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingRentalHandout;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffRentalHandoutTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('staff');

        $trip = Trip::create([
            'title' => 'ดอยม่อนจอง',
            'slug' => 'rental-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2900,
            'status' => 'active',
            'rental_items' => [
                ['name' => 'เต็นท์ 2 คน', 'price' => 300],
                ['name' => 'ถุงนอน', 'price' => 150],
            ],
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(5)->toDateString(),
            'return_date' => now()->addDays(6)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $this->staff = User::factory()->create(['name' => 'สตาฟต้น']);
        $this->staff->assignRole('staff');
        $this->schedule->staff()->attach($this->staff->id);
    }

    private function bookWithRentals(array $rentals, string $status = 'confirmed'): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['name' => 'ลูกค้า A'])->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 2900,
            'selected_rentals' => $rentals,
            'rentals_total' => collect($rentals)->sum('total_price'),
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'ผู้โดยสาร',
            'phone' => '0811111111',
        ]);

        return $booking;
    }

    private function tent(int $quantity = 1): array
    {
        return [
            'name' => 'เต็นท์ 2 คน',
            'unit_price' => 300,
            'quantity' => $quantity,
            'total_price' => 300 * $quantity,
            'image_url' => '',
        ];
    }

    private function sleepingBag(int $quantity = 1): array
    {
        return [
            'name' => 'ถุงนอน',
            'unit_price' => 150,
            'quantity' => $quantity,
            'total_price' => 150 * $quantity,
            'image_url' => '',
        ];
    }

    public function test_list_totals_every_rented_piece_in_the_round(): void
    {
        $this->bookWithRentals([$this->tent(1), $this->sleepingBag(2)]);
        $this->bookWithRentals([$this->tent(2)]);
        // การจองที่ไม่ได้เช่าอะไร ต้องไม่โผล่ในใบแจก
        $this->bookWithRentals([]);

        $data = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$this->schedule->id}/rentals")
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['summary']['bookings']);
        $this->assertSame(5, $data['summary']['total_pieces']);
        $this->assertSame(0, $data['summary']['handed_out_pieces']);

        $tent = collect($data['items'])->firstWhere('name', 'เต็นท์ 2 คน');
        $this->assertSame(3, $tent['quantity']);
        $bag = collect($data['items'])->firstWhere('name', 'ถุงนอน');
        $this->assertSame(2, $bag['quantity']);
    }

    public function test_staff_marks_handout_then_return(): void
    {
        $booking = $this->bookWithRentals([$this->tent(2)]);
        $url = "/api/v1/staff/schedules/{$this->schedule->id}/rentals/mark";

        $data = $this->actingAs($this->staff, 'sanctum')
            ->postJson($url, [
                'booking_ref' => $booking->booking_ref,
                'item_name' => 'เต็นท์ 2 คน',
                'action' => 'handout',
                'done' => true,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['summary']['handed_out_pieces']);
        $this->assertTrue($data['bookings'][0]['items'][0]['handed_out']);
        $this->assertTrue($data['bookings'][0]['all_handed_out']);
        $this->assertFalse($data['bookings'][0]['all_returned']);

        $handout = BookingRentalHandout::firstOrFail();
        $this->assertSame($this->staff->id, $handout->handed_out_by_id);
        $this->assertSame(2, $handout->quantity);

        $data = $this->actingAs($this->staff, 'sanctum')
            ->postJson($url, [
                'booking_ref' => $booking->booking_ref,
                'item_name' => 'เต็นท์ 2 คน',
                'action' => 'return',
                'done' => true,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['summary']['returned_pieces']);
        $this->assertTrue($data['bookings'][0]['all_returned']);
    }

    public function test_cannot_return_before_handing_out(): void
    {
        $booking = $this->bookWithRentals([$this->tent()]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$this->schedule->id}/rentals/mark", [
                'booking_ref' => $booking->booking_ref,
                'item_name' => 'เต็นท์ 2 คน',
                'action' => 'return',
                'done' => true,
            ])
            ->assertStatus(422);
    }

    public function test_undoing_a_handout_clears_the_return_too(): void
    {
        $booking = $this->bookWithRentals([$this->tent()]);
        $url = "/api/v1/staff/schedules/{$this->schedule->id}/rentals/mark";

        foreach (['handout', 'return'] as $action) {
            $this->actingAs($this->staff, 'sanctum')->postJson($url, [
                'booking_ref' => $booking->booking_ref,
                'item_name' => 'เต็นท์ 2 คน',
                'action' => $action,
                'done' => true,
            ])->assertOk();
        }

        $data = $this->actingAs($this->staff, 'sanctum')
            ->postJson($url, [
                'booking_ref' => $booking->booking_ref,
                'item_name' => 'เต็นท์ 2 คน',
                'action' => 'handout',
                'done' => false,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['summary']['handed_out_pieces']);
        $this->assertSame(0, $data['summary']['returned_pieces']);
        $this->assertFalse($data['bookings'][0]['items'][0]['returned']);
    }

    public function test_unknown_item_is_rejected(): void
    {
        $booking = $this->bookWithRentals([$this->tent()]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$this->schedule->id}/rentals/mark", [
                'booking_ref' => $booking->booking_ref,
                'item_name' => 'ไม้เท้าเดินป่า',
                'action' => 'handout',
                'done' => true,
            ])
            ->assertStatus(422);
    }

    public function test_staff_not_on_the_round_is_blocked(): void
    {
        $booking = $this->bookWithRentals([$this->tent()]);
        $other = User::factory()->create();
        $other->assignRole('staff');

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$this->schedule->id}/rentals")
            ->assertStatus(403);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$this->schedule->id}/rentals/mark", [
                'booking_ref' => $booking->booking_ref,
                'item_name' => 'เต็นท์ 2 คน',
                'action' => 'handout',
                'done' => true,
            ])
            ->assertStatus(403);

        // ลูกค้าธรรมดาก็เข้าไม่ได้
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$this->schedule->id}/rentals")
            ->assertStatus(403);
    }

    public function test_manifest_lists_the_rentals_of_each_booking(): void
    {
        $booking = $this->bookWithRentals([$this->tent(1), $this->sleepingBag(2)]);

        $data = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$this->schedule->id}/manifest")
            ->assertOk()
            ->json('data');

        $row = collect($data['bookings'])->firstWhere('booking_ref', $booking->booking_ref);
        $this->assertSame('เต็นท์ 2 คน', $row['selected_rentals'][0]['name']);
        $this->assertSame(2, $row['selected_rentals'][1]['quantity']);
    }

    public function test_staff_can_check_in_a_booking_by_its_ref(): void
    {
        $booking = $this->bookWithRentals([$this->tent()]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->booking_ref,
                'schedule_id' => $this->schedule->id,
            ])
            ->assertOk();

        $this->assertTrue($booking->fresh()->checked_in);
    }
}
