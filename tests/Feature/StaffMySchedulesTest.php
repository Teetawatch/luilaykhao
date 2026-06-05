<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffMySchedulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_confirmed_counts_passengers_not_bookings(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $schedule = $this->makeSchedule();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Mix of single- and multi-passenger bookings: 7 bookings → 9 passengers
        // (mirrors the user-reported scenario of "9 คนแต่ขึ้น 7").
        $this->makeBooking($schedule, passengerCount: 2);
        $this->makeBooking($schedule, passengerCount: 2);
        $this->makeBooking($schedule, passengerCount: 1);
        $this->makeBooking($schedule, passengerCount: 1);
        $this->makeBooking($schedule, passengerCount: 1);
        $this->makeBooking($schedule, passengerCount: 1);
        $this->makeBooking($schedule, passengerCount: 1);

        $response = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/staff/schedules/my');

        $response->assertOk();
        $payload = $response->json('data.schedules.0');
        $this->assertSame(9, $payload['total_confirmed']);
        $this->assertSame(0, $payload['checked_in_count']);
    }

    public function test_checked_in_count_reflects_passenger_seats(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $schedule = $this->makeSchedule();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // 2 bookings: a 3-seat group that's checked in, plus a single not yet
        // checked in. Expected: 3 of 4 checked in (per-seat headcount).
        $this->makeBooking($schedule, passengerCount: 3, checkedIn: true);
        $this->makeBooking($schedule, passengerCount: 1);

        $payload = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/staff/schedules/my')
            ->json('data.schedules.0');

        $this->assertSame(4, $payload['total_confirmed']);
        $this->assertSame(3, $payload['checked_in_count']);
    }

    public function test_pickup_breakdown_groups_passengers_by_per_passenger_pickup(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $schedule = $this->makeSchedule();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $pointA = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'north',
            'region_label' => 'Region A',
            'pickup_location' => 'Point A',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $pointB = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'south',
            'region_label' => 'Region B',
            'pickup_location' => 'Point B',
            'price' => 0,
            'sort_order' => 2,
        ]);

        // One group booking with two passengers split between pickup points —
        // overrides the booking-level pickup. The breakdown must count each
        // passenger under their own pickup, not roll them up to the booking's.
        $booking = $this->makeBooking($schedule, passengerCount: 0, pickupPointId: $pointA->id);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Passenger A1',
            'pickup_point_id' => $pointA->id,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Passenger A2',
            'pickup_point_id' => $pointA->id,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Passenger B1',
            'pickup_point_id' => $pointB->id,
        ]);

        $payload = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/staff/schedules/my')
            ->json('data.schedules.0');

        $this->assertSame(3, $payload['total_confirmed']);

        $breakdown = collect($payload['pickup_breakdown'])->keyBy('id');
        $this->assertSame(2, $breakdown[$pointA->id]['passenger_count']);
        $this->assertSame(1, $breakdown[$pointB->id]['passenger_count']);
    }

    public function test_manifest_groups_passengers_by_pickup_with_full_info_and_vehicle(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $schedule = $this->makeSchedule();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ A',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'กข 1234',
            'color' => 'ขาว',
            'driver_name' => 'พี่ต้น',
            'driver_phone' => '0801112222',
        ]);
        $schedule->vehicle_id = $vehicle->id;
        $schedule->save();

        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'north',
            'region_label' => 'โซนเหนือ',
            'pickup_location' => 'ปั๊ม ปตท. A',
            'price' => 0,
            'sort_order' => 1,
        ]);

        // A checked-in booking at the pickup point with a fully-detailed passenger.
        $booking = $this->makeBooking($schedule, passengerCount: 0, checkedIn: true, pickupPointId: $point->id);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'phone' => '0811111111',
        ]);
        // A second booking with no pickup point → "ไม่ระบุจุดรับ" group, not checked in.
        $this->makeBooking($schedule, passengerCount: 1);

        $data = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->json('data');

        // Vehicle details incl. license plate.
        $this->assertSame('กข 1234', $data['schedule']['vehicle']['license_plate']);
        $this->assertSame('van', $data['schedule']['vehicle']['type']);

        // Passenger-based check-in summary: 1 of 2 in.
        $this->assertSame(2, $data['summary']['passengers']);
        $this->assertSame(1, $data['summary']['checked_in_passengers']);

        // Pickup groups: the named point + the no-pickup bucket.
        $groups = collect($data['pickup_groups']);
        $this->assertCount(2, $groups);

        $named = $groups->firstWhere('label', 'ปั๊ม ปตท. A');
        $this->assertSame(1, $named['passenger_count']);
        $this->assertSame(1, $named['checked_in_count']);

        $p = $named['passengers'][0];
        $this->assertSame('นาย สมชาย ใจดี', $p['full_name']);
        $this->assertSame('ชาย', $p['nickname']);
        $this->assertSame('0811111111', $p['phone']);
        $this->assertTrue($p['checked_in']);

        $this->assertNotNull($groups->firstWhere('label', 'ไม่ระบุจุดรับ'));
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeBooking(
        TripSchedule $schedule,
        int $passengerCount = 1,
        bool $checkedIn = false,
        ?int $pickupPointId = null,
    ): Booking {
        $customer = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => sprintf('TEST-%s-%04d', now()->format('Ymd'), Booking::count() + 1),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'pickup_point_id' => $pickupPointId,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'paid_amount' => 1500,
            'checked_in' => $checkedIn,
        ]);

        for ($i = 0; $i < $passengerCount; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => 'Passenger '.($i + 1),
                'phone' => '0800000000',
            ]);
        }

        return $booking;
    }
}
