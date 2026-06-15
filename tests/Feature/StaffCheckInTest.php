<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
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

    public function test_manifest_surfaces_passenger_safety_info_and_care_alert_count(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Add a passenger who needs care attention.
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Care Passenger',
            'phone' => '0811111111',
            'allergies' => 'ถั่ว',
            'health_notes' => 'หอบหืด',
            'halal_food' => true,
            'blood_group' => 'O',
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0822222222',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->assertJsonPath('data.bookings.0.passengers.1.allergies', 'ถั่ว')
            ->assertJsonPath('data.bookings.0.passengers.1.health_notes', 'หอบหืด')
            ->assertJsonPath('data.bookings.0.passengers.1.halal_food', true)
            ->assertJsonPath('data.bookings.0.passengers.1.blood_group', 'O')
            ->assertJsonPath('data.bookings.0.passengers.1.emergency_contact', 'แม่')
            ->assertJsonPath('data.bookings.0.passengers.1.emergency_phone', '0822222222')
            // 'Test Passenger' has no flags, the new one does → exactly one alert.
            ->assertJsonPath('data.summary.care_alerts', 1);
    }

    public function test_roll_call_toggle_checks_booking_in_and_out_with_headcount(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // A second passenger so the head-count is meaningful.
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Second Passenger',
            'phone' => '0833333333',
        ]);

        // Check in → 2 passengers counted.
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/check-in/set", [
                'booking_ref' => $booking->booking_ref,
                'checked_in' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.checked_in', true)
            ->assertJsonPath('data.summary.checked_in', 1)
            ->assertJsonPath('data.summary.checked_in_passengers', 2)
            ->assertJsonPath('data.summary.passengers', 2);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'checked_in' => true,
        ]);

        // Un-check (reversible) → back to 0.
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/check-in/set", [
                'booking_ref' => $booking->booking_ref,
                'checked_in' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.checked_in', false)
            ->assertJsonPath('data.summary.checked_in_passengers', 0);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'checked_in' => false,
            'checked_in_at' => null,
        ]);
    }

    public function test_unassigned_staff_cannot_toggle_roll_call(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/check-in/set", [
                'booking_ref' => $booking->booking_ref,
                'checked_in' => true,
            ])
            ->assertForbidden();
    }

    public function test_completing_a_pickup_point_notifies_next_stop_passengers(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $firstBooking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Two pickup points in route order.
        $stop1 = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'Stop 1',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $stop2 = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'Stop 2',
            'price' => 0,
            'sort_order' => 2,
        ]);

        $firstBooking->update(['pickup_point_id' => $stop1->id]);

        // A customer waiting at stop 2.
        $nextCustomer = User::factory()->create();
        Booking::create([
            'booking_ref' => Booking::generateRef().'-'.uniqid(),
            'user_id' => $nextCustomer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop2->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/pickup-points/{$stop1->id}/complete", [
                'completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.next_point.id', $stop2->id)
            ->assertJsonPath('data.notified', 1);

        $this->assertNotNull($stop1->fresh()->completed_at);

        // The next-stop customer received a heads-up notification.
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $nextCustomer->id,
            'type' => 'pickup_approaching',
        ]);

        // Undo clears completion without notifying again.
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/pickup-points/{$stop1->id}/complete", [
                'completed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.completed_at', null);

        $this->assertNull($stop1->fresh()->completed_at);
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
