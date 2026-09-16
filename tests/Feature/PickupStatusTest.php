<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ลูกค้ากดบอกสถานะตัวเองที่จุดนัด แล้วสตาฟเห็นในรายชื่อโดยไม่ต้องโทรถาม
 */
class PickupStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_reports_arrival_and_staff_manifest_shows_it(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking, $customer] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'arrived',
            ])
            ->assertOk()
            ->assertJsonPath('data.pickup_status', 'arrived')
            ->assertJsonPath('data.label', 'ถึงจุดนัดแล้ว');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'pickup_status' => 'arrived',
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk();

        $group = $response->json('data.pickup_groups.0');
        $this->assertSame('arrived', $group['passengers'][0]['pickup_status']);
        $this->assertSame(1, $group['arrived_count']);
        $this->assertSame(0, $group['late_count']);
    }

    public function test_reporting_late_notifies_assigned_staff_with_the_estimate(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking, $customer] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'late',
                'eta_minutes' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.pickup_status_eta_minutes', 15)
            ->assertJsonPath('data.label', 'อาจสาย ~15 นาที');

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $staff->id,
            'type' => 'pickup_late',
        ]);

        $notification = SmartNotification::where('user_id', $staff->id)->first();
        $this->assertStringContainsString('15 นาที', $notification->body);
        $this->assertStringContainsString('จุดขึ้นรถหมอชิต', $notification->body);
    }

    public function test_pressing_late_twice_with_the_same_estimate_does_not_notify_again(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking, $customer] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $payload = ['status' => 'late', 'eta_minutes' => 15];

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", $payload)
            ->assertOk();
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", $payload)
            ->assertOk();

        $this->assertSame(1, SmartNotification::where('type', 'pickup_late')->count());

        // แต่พอตัวเลขเปลี่ยน (สายกว่าเดิม) ต้องดังใหม่ — นั่นคือข้อมูลใหม่จริง ๆ
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'late',
                'eta_minutes' => 40,
            ])
            ->assertOk();

        $this->assertSame(2, SmartNotification::where('type', 'pickup_late')->count());
    }

    public function test_switching_away_from_late_clears_the_estimate(): void
    {
        [$schedule, $booking, $customer] = $this->createConfirmedBooking();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'late',
                'eta_minutes' => 20,
            ])->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'arrived',
            ])->assertOk();

        $this->assertNull($booking->fresh()->pickup_status_eta_minutes);
    }

    public function test_companion_on_the_booking_can_report_too(): void
    {
        [$schedule, $booking, $customer] = $this->createConfirmedBooking();

        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'status' => BookingMember::STATUS_ACTIVE,
            'name' => 'Friend',
        ]);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'on_the_way',
            ])
            ->assertOk()
            ->assertJsonPath('data.pickup_status', 'on_the_way');
    }

    public function test_a_stranger_cannot_report_on_someone_elses_booking(): void
    {
        [$schedule, $booking] = $this->createConfirmedBooking();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'arrived',
            ])
            ->assertStatus(404);
    }

    public function test_reporting_is_closed_outside_the_departure_window(): void
    {
        [$schedule, $booking, $customer] = $this->createConfirmedBooking();
        $schedule->update([
            'departure_date' => now()->addDays(9)->toDateString(),
            'return_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'arrived',
            ])
            ->assertStatus(422);
    }

    public function test_a_checked_in_booking_no_longer_reports_status(): void
    {
        [$schedule, $booking, $customer] = $this->createConfirmedBooking();
        $booking->update(['checked_in' => true, 'checked_in_at' => now()]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/pickup-status", [
                'status' => 'arrived',
            ])
            ->assertStatus(422);
    }

    public function test_yesterdays_status_is_not_shown_as_todays(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $booking->update([
            'pickup_status' => 'arrived',
            'pickup_status_at' => now()->subHours(20),
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk();

        $group = $response->json('data.pickup_groups.0');
        $this->assertNull($group['passengers'][0]['pickup_status']);
        $this->assertSame(0, $group['arrived_count']);
    }

    /** @return array{0: TripSchedule, 1: Booking, 2: User} */
    private function createConfirmedBooking(): array
    {
        $customer = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Pickup Status Trip',
            'slug' => 'pickup-status-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'จุดขึ้นรถหมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $point->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'คุณลูกค้า',
            'phone' => '0800000000',
            'pickup_point_id' => $point->id,
        ]);

        return [$schedule, $booking, $customer];
    }
}
