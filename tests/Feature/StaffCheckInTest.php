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

class StaffCheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_staff_can_lookup_booking_then_confirm_check_in(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/lookup', [
                'qr_code' => $booking->qr_code,
            ])
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.checked_in', false)
            ->assertJsonPath('data.passengers.0.name', 'Test Passenger')
            ->assertJsonPath('meta.can_check_in', true);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'checked_in' => false,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
            ])
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.checked_in', true);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'checked_in' => true,
        ]);
    }

    public function test_unassigned_staff_cannot_lookup_booking_for_another_schedule(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [, $booking] = $this->createConfirmedBooking();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/lookup', [
                'qr_code' => $booking->qr_code,
            ])
            ->assertForbidden();
    }

    private function createConfirmedBooking(): array
    {
        $customer = User::factory()->create();
        $trip = Trip::create([
            'title' => 'Staff Check-in Trip',
            'slug' => 'staff-check-in-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef().'-'.uniqid(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Test Passenger',
            'phone' => '0800000000',
        ]);

        return [$schedule, $booking];
    }
}
