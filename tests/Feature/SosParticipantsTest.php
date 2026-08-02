<?php

namespace Tests\Feature;

use App\Jobs\BroadcastSosAlert;
use App\Jobs\DeliverSosAlert;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\SosAlert;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SosParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ใครกด SOS ได้ และใครได้รับสัญญาณ
 *
 * เดิมสองฝั่งนิยาม "คนในทริป" ไม่ตรงกัน: เพื่อนร่วมใบจอง (BookingMember) เห็น
 * ปุ่ม SOS ในแอปแต่กดแล้วได้ 404 ส่วนคนขับซึ่งผูกกับรอบผ่านรถ ไม่ใช่ pivot สตาฟ
 * ก็ไม่เคยได้รับสัญญาณ เทสต์นี้ล็อกไว้ว่าทั้งคู่ต้องอยู่ในระบบ
 */
class SosParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'sos-part-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 2, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $this->owner = User::factory()->create(['phone' => '0811111111']);
    }

    private function confirmedBooking(?User $user = null): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => ($user ?? $this->owner)->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
            'payment_type' => 'full',
        ]);
    }

    public function test_companion_on_someone_elses_booking_can_trigger_sos(): void
    {
        Bus::fake();

        $booking = $this->confirmedBooking();
        $companion = User::factory()->create(['phone' => '0822222222']);

        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'accepted_at' => now(),
        ]);

        $this->actingAs($companion, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $this->schedule->id])
            ->assertOk();

        $this->assertDatabaseHas('sos_alerts', [
            'user_id' => $companion->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'active',
        ]);
    }

    public function test_a_stranger_still_cannot_trigger_sos_for_the_round(): void
    {
        Bus::fake();
        $this->confirmedBooking();

        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $this->schedule->id])
            ->assertStatus(404);

        $this->assertDatabaseCount('sos_alerts', 0);
    }

    public function test_pending_companion_invites_do_not_grant_sos_access(): void
    {
        Bus::fake();

        $booking = $this->confirmedBooking();
        $invited = User::factory()->create();

        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $invited->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_PENDING,
        ]);

        $this->actingAs($invited, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $this->schedule->id])
            ->assertStatus(404);
    }

    public function test_driver_and_companion_receive_the_alert(): void
    {
        Bus::fake();
        Mail::fake();

        $booking = $this->confirmedBooking();

        $companion = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'accepted_at' => now(),
        ]);

        $driver = User::factory()->create();
        $driver->assignRole('driver');
        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'capacity' => 12,
            'license_plate' => 'กก-1234',
            'driver_user_id' => $driver->id,
            'status' => 'active',
        ]);
        $this->schedule->update(['vehicle_id' => $vehicle->id]);

        $staff = User::factory()->create();
        $this->schedule->staff()->attach($staff->id);

        $ops = User::factory()->create();
        $ops->assignRole('operator');

        $alert = SosAlert::create([
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'message' => 'หลงทาง',
            'contact_phone' => '0811111111',
            'status' => 'active',
        ]);

        (new BroadcastSosAlert($alert->id))->handle(app(SosParticipantService::class));

        foreach ([$companion, $driver, $staff, $ops] as $recipient) {
            Bus::assertDispatched(
                DeliverSosAlert::class,
                fn (DeliverSosAlert $job) => $this->recipientOf($job) === $recipient->id,
            );
        }

        // คนที่กดเองไม่ต้องได้รับสัญญาณของตัวเอง
        Bus::assertNotDispatched(
            DeliverSosAlert::class,
            fn (DeliverSosAlert $job) => $this->recipientOf($job) === $this->owner->id,
        );
    }

    public function test_released_staff_no_longer_receive_alerts(): void
    {
        Bus::fake();
        Mail::fake();

        $this->confirmedBooking();

        $released = User::factory()->create();
        $this->schedule->staff()->attach($released->id, ['released_at' => now()]);

        $alert = SosAlert::create([
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'active',
        ]);

        (new BroadcastSosAlert($alert->id))->handle(app(SosParticipantService::class));

        Bus::assertNotDispatched(
            DeliverSosAlert::class,
            fn (DeliverSosAlert $job) => $this->recipientOf($job) === $released->id,
        );
    }

    private function recipientOf(DeliverSosAlert $job): int
    {
        $reflection = new \ReflectionProperty($job, 'recipientUserId');

        return (int) $reflection->getValue($job);
    }
}
