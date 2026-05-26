<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingTransferTest extends TestCase
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

    private function makeBooking(User $owner): Booking
    {
        Mail::fake();

        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip-transfer',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return app(BookingService::class)->createBooking(
            userId: $owner->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Mr.',
                'name' => 'Test Passenger',
                'phone' => '0812345678',
                'email' => 'passenger@example.test',
            ]],
        );
    }

    public function test_admin_can_transfer_booking_by_user_id(): void
    {
        $owner = User::factory()->create();
        $newOwner = User::factory()->create();
        $booking = $this->makeBooking($owner);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/transfer", [
                'user_id' => $newOwner->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.new_user.id', $newOwner->id);

        $this->assertDatabaseHas('bookings', [
            'booking_ref' => $booking->booking_ref,
            'user_id' => $newOwner->id,
        ]);
    }

    public function test_admin_can_transfer_booking_by_email(): void
    {
        $owner = User::factory()->create();
        $newOwner = User::factory()->create(['email' => 'target@example.test']);
        $booking = $this->makeBooking($owner);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/transfer", [
                'email' => 'target@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.new_user.id', $newOwner->id);
    }

    public function test_admin_can_transfer_booking_by_phone(): void
    {
        $owner = User::factory()->create();
        $newOwner = User::factory()->create(['phone' => '0899999999']);
        $booking = $this->makeBooking($owner);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/transfer", [
                'phone' => '0899999999',
            ])
            ->assertOk()
            ->assertJsonPath('data.new_user.id', $newOwner->id);
    }

    public function test_transfer_fails_when_user_not_found(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/transfer", [
                'email' => 'nobody@example.test',
            ])
            ->assertStatus(404);
    }

    public function test_transfer_fails_when_already_same_owner(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/transfer", [
                'user_id' => $owner->id,
            ])
            ->assertStatus(422);
    }

    public function test_transfer_fails_on_cancelled_booking(): void
    {
        $owner = User::factory()->create();
        $newOwner = User::factory()->create();
        $booking = $this->makeBooking($owner);
        $booking->update(['status' => 'cancelled']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/transfer", [
                'user_id' => $newOwner->id,
            ])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_transfer_booking(): void
    {
        $customer = User::factory()->create();
        $newOwner = User::factory()->create();
        $booking = $this->makeBooking($customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/transfer", [
                'user_id' => $newOwner->id,
            ])
            ->assertForbidden();
    }
}
