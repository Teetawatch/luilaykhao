<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRentalHandout;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แอดมินเพิ่ม/แก้/ลบอุปกรณ์เช่า (เต็นท์ ถุงนอน หมอน) ของการจองที่มีอยู่แล้วได้
 * จากหน้าจัดการการจอง — ลูกค้ามักขอเช่าเพิ่มทีหลังจากที่จองไปแล้ว
 */
class AdminBookingRentalEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeBooking(array $overrides = []): Booking
    {
        $trip = Trip::create([
            'title' => 'Rental Trip', 'slug' => 'rental-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
            'rental_items' => [
                ['name' => 'เต็นท์ 2 คน', 'price' => 300, 'image_url' => 'https://cdn.test/tent.jpg'],
                ['name' => 'ถุงนอน', 'price' => 150],
            ],
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create(array_merge([
            'booking_ref' => 'LLK-RENT-'.uniqid(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'payment_type' => 'full',
            'total_amount' => 3000,
            'paid_amount' => 3000,
        ], $overrides));
    }

    public function test_admin_can_add_rentals_to_an_existing_booking(): void
    {
        $booking = $this->makeBooking();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'sync_rentals' => 1,
                'selected_rentals' => [
                    ['name' => 'เต็นท์ 2 คน', 'unit_price' => 300, 'quantity' => 2, 'image_url' => 'https://cdn.test/tent.jpg'],
                    ['name' => 'ถุงนอน', 'unit_price' => 150, 'quantity' => 1],
                ],
            ])
            ->assertOk();

        $booking->refresh();

        $this->assertCount(2, $booking->selected_rentals);
        $this->assertSame(750.0, (float) $booking->rentals_total);
        $this->assertSame('เต็นท์ 2 คน', $booking->selected_rentals[0]['name']);
        $this->assertSame(600.0, (float) $booking->selected_rentals[0]['total_price']);
        $this->assertSame('https://cdn.test/tent.jpg', $booking->selected_rentals[0]['image_url']);
        $this->assertSame('', $booking->selected_rentals[1]['image_url']);
    }

    public function test_rentals_total_is_recomputed_server_side(): void
    {
        $booking = $this->makeBooking();

        // ราคารวมที่ client ส่งมาไม่ถูกใช้ — server คูณเองจากราคาต่อชิ้น × จำนวน
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'sync_rentals' => 1,
                'selected_rentals' => [
                    ['name' => 'หมอน', 'unit_price' => 50, 'quantity' => 3, 'total_price' => 99999],
                ],
            ])
            ->assertOk();

        $booking->refresh();

        $this->assertSame(150.0, (float) $booking->rentals_total);
        $this->assertSame(150.0, (float) $booking->selected_rentals[0]['total_price']);
    }

    public function test_admin_can_clear_all_rentals(): void
    {
        $booking = $this->makeBooking([
            'selected_rentals' => [
                ['name' => 'ถุงนอน', 'unit_price' => 150, 'quantity' => 1, 'total_price' => 150, 'image_url' => ''],
            ],
            'rentals_total' => 150,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'sync_rentals' => 1,
            ])
            ->assertOk();

        $booking->refresh();

        $this->assertSame([], $booking->selected_rentals);
        $this->assertSame(0.0, (float) $booking->rentals_total);
    }

    public function test_removing_an_item_clears_its_staff_handout_state(): void
    {
        $booking = $this->makeBooking([
            'selected_rentals' => [
                ['name' => 'เต็นท์ 2 คน', 'unit_price' => 300, 'quantity' => 1, 'total_price' => 300, 'image_url' => ''],
                ['name' => 'ถุงนอน', 'unit_price' => 150, 'quantity' => 1, 'total_price' => 150, 'image_url' => ''],
            ],
            'rentals_total' => 450,
        ]);

        BookingRentalHandout::create([
            'booking_id' => $booking->id, 'item_name' => 'เต็นท์ 2 คน', 'quantity' => 1, 'handed_out_at' => now(),
        ]);
        BookingRentalHandout::create([
            'booking_id' => $booking->id, 'item_name' => 'ถุงนอน', 'quantity' => 1, 'handed_out_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'sync_rentals' => 1,
                'selected_rentals' => [
                    ['name' => 'ถุงนอน', 'unit_price' => 150, 'quantity' => 1],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('booking_rental_handouts', [
            'booking_id' => $booking->id, 'item_name' => 'เต็นท์ 2 คน',
        ]);
        $this->assertDatabaseHas('booking_rental_handouts', [
            'booking_id' => $booking->id, 'item_name' => 'ถุงนอน',
        ]);
    }

    public function test_rentals_are_left_alone_when_sync_flag_is_absent(): void
    {
        $booking = $this->makeBooking([
            'selected_rentals' => [
                ['name' => 'ถุงนอน', 'unit_price' => 150, 'quantity' => 1, 'total_price' => 150, 'image_url' => ''],
            ],
            'rentals_total' => 150,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'group_name' => 'ทีมบ้านนา',
            ])
            ->assertOk();

        $booking->refresh();

        $this->assertCount(1, $booking->selected_rentals);
        $this->assertSame(150.0, (float) $booking->rentals_total);
    }

    public function test_rental_rows_are_validated(): void
    {
        $booking = $this->makeBooking();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'sync_rentals' => 1,
                'selected_rentals' => [
                    ['name' => '', 'unit_price' => -5, 'quantity' => 0],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'selected_rentals.0.name',
                'selected_rentals.0.unit_price',
                'selected_rentals.0.quantity',
            ]);
    }
}
