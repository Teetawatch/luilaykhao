<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PickupArrivalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หมุดจุดรับ: ของใบจอง (ทั้งกลุ่ม) และรายคน (คนในใบเดียวกันขึ้นคนละที่) — หน้าสตาฟ
 * ทุกจุดต้องเห็นหมุดเป็นจุดของมันเอง ไม่ใช่กองอยู่ใน "ไม่ระบุจุดรับ"
 */
class PassengerCustomPickupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_staff_overview_lists_a_booking_pin_instead_of_no_pickup(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();

        $booking = $this->makeBooking($schedule, passengerCount: 2);
        $booking->update([
            'custom_pickup_label' => 'หน้าหมู่บ้านสุขใจ',
            'custom_pickup_lat' => 13.7563,
            'custom_pickup_lng' => 100.5018,
            'custom_pickup_status' => Booking::CUSTOM_PICKUP_APPROVED,
        ]);

        $payload = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/staff/schedules/my')
            ->assertOk()
            ->json('data.schedules.0');

        $this->assertSame(0, $payload['no_pickup_count']);
        $pin = collect($payload['pickup_breakdown'])->firstWhere('is_custom', true);
        $this->assertNotNull($pin, 'booking pin missing from the staff overview');
        $this->assertSame('หน้าหมู่บ้านสุขใจ', $pin['label']);
        $this->assertSame(2, $pin['passenger_count']);
        $this->assertStringContainsString('13.7563,100.5018', $pin['map_url']);
    }

    public function test_admin_pins_one_passenger_and_staff_see_them_apart_from_the_group(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();
        $point = $this->makePoint($schedule);

        $booking = $this->makeBooking($schedule, passengerCount: 2, pickupPointId: $point->id);
        [$first, $second] = $booking->passengers->all();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'pickup_point_id' => $point->id,
                'passengers' => [
                    ['id' => $first->id, 'name' => $first->name, 'pickup_point_id' => $point->id,
                        'custom_pickup_lat' => '', 'custom_pickup_lng' => ''],
                    ['id' => $second->id, 'name' => $second->name, 'pickup_point_id' => $point->id,
                        'custom_pickup_label' => 'ปากซอยบ้าน', 'custom_pickup_lat' => 13.9, 'custom_pickup_lng' => 100.6,
                        'custom_pickup_note' => 'หน้าเซเว่น'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.passengers.1.custom_pickup.label', 'ปากซอยบ้าน')
            ->assertJsonPath('data.passengers.0.custom_pickup', null);

        $second->refresh();
        $this->assertNull($second->pickup_point_id, 'a pinned passenger keeps no fixed point');
        $this->assertSame(13.9, $second->custom_pickup_lat);

        // รายชื่อสตาฟ: คนแรกอยู่จุดตายตัว คนที่สองอยู่หมุดของตัวเอง
        $groups = collect($this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->json('data.pickup_groups'));

        $this->assertSame(1, $groups->firstWhere('id', $point->id)['passenger_count']);
        $pinGroup = $groups->firstWhere('is_custom', true);
        $this->assertSame('ปากซอยบ้าน', $pinGroup['label']);
        $this->assertSame('หน้าเซเว่น', $pinGroup['notes']);
        $this->assertSame('ปักหมุดรายคน', $pinGroup['region_label']);
        $this->assertSame([$second->name], array_column($pinGroup['passengers'], 'name'));
        $this->assertNull($groups->firstWhere('label', 'ไม่ระบุจุดรับ'));

        // หน้ารายการงาน: เหมือนกัน
        $overview = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/staff/schedules/my')
            ->json('data.schedules.0');
        $breakdown = collect($overview['pickup_breakdown']);
        $this->assertSame(1, $breakdown->firstWhere('id', $point->id)['passenger_count']);
        $this->assertSame(1, $breakdown->firstWhere('is_custom', true)['passenger_count']);
        $this->assertSame(0, $overview['no_pickup_count']);

        // คนที่รออยู่ที่หมุดไม่ถูกนับเป็นคนรอที่จุดตายตัว
        $this->assertSame(
            [$point->id => 1],
            app(PickupArrivalService::class)->waitingCounts($schedule->fresh('pickupPoints')),
        );
    }

    public function test_passenger_pin_wins_over_the_booking_pin(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();

        $booking = $this->makeBooking($schedule, passengerCount: 3);
        $booking->update([
            'custom_pickup_label' => 'หมุดของกลุ่ม',
            'custom_pickup_lat' => 13.7,
            'custom_pickup_lng' => 100.5,
            'custom_pickup_status' => Booking::CUSTOM_PICKUP_APPROVED,
        ]);
        $booking->passengers->last()->update([
            'custom_pickup_label' => 'หมุดของคนสุดท้าย',
            'custom_pickup_lat' => 14.0,
            'custom_pickup_lng' => 100.9,
        ]);

        $groups = collect($this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->json('data.pickup_groups'))
            ->where('is_custom', true)
            ->keyBy('label');

        $this->assertSame(2, $groups['หมุดของกลุ่ม']['passenger_count']);
        $this->assertSame(1, $groups['หมุดของคนสุดท้าย']['passenger_count']);
    }

    public function test_admin_clears_a_passenger_pin_with_empty_coordinates(): void
    {
        [, $schedule] = $this->staffOnSchedule();
        $point = $this->makePoint($schedule);
        $booking = $this->makeBooking($schedule, passengerCount: 1, pickupPointId: $point->id);
        $passenger = $booking->passengers->first();
        $passenger->update([
            'custom_pickup_label' => 'หมุดเก่า',
            'custom_pickup_lat' => 14.0,
            'custom_pickup_lng' => 100.9,
            'pickup_point_id' => null,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'passengers' => [
                    ['id' => $passenger->id, 'name' => $passenger->name, 'pickup_point_id' => $point->id,
                        'custom_pickup_label' => '', 'custom_pickup_lat' => '', 'custom_pickup_lng' => '', 'custom_pickup_note' => ''],
                ],
            ])
            ->assertOk();

        $passenger->refresh();
        $this->assertNull($passenger->custom_pickup_lat);
        $this->assertNull($passenger->custom_pickup_label);
        $this->assertSame($point->id, (int) $passenger->pickup_point_id);
    }

    public function test_a_passenger_pin_needs_both_coordinates(): void
    {
        [, $schedule] = $this->staffOnSchedule();
        $booking = $this->makeBooking($schedule, passengerCount: 1);
        $passenger = $booking->passengers->first();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'passengers' => [
                    ['id' => $passenger->id, 'name' => $passenger->name, 'custom_pickup_lat' => 13.9],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passengers.0.custom_pickup_lng');
    }

    /** @return array{0: User, 1: TripSchedule} */
    private function staffOnSchedule(): array
    {
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $trip = Trip::create([
            'title' => 'Pin Trip',
            'slug' => 'pin-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return [$staff, $schedule];
    }

    private function makePoint(TripSchedule $schedule): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);
    }

    private function makeBooking(TripSchedule $schedule, int $passengerCount, ?int $pickupPointId = null): Booking
    {
        $booking = Booking::create([
            'booking_ref' => sprintf('PIN-%s-%04d', now()->format('Ymd'), Booking::count() + 1),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'pickup_point_id' => $pickupPointId,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'paid_amount' => 1500,
            'checked_in' => false,
        ]);

        for ($i = 1; $i <= $passengerCount; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => "ผู้เดินทาง {$i}",
                'phone' => '0800000000',
                'pickup_point_id' => $pickupPointId,
            ]);
        }

        return $booking->load('passengers');
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }
}
