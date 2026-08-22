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

/**
 * หน้ารายชื่อของสตาฟต้องแยกให้ออกว่าใคร "จอยทริป" (ไปเจอกันเองหน้างาน ไม่มีจุดขึ้นรถ)
 * และใคร "จองปกติ" (รอขึ้นรถตามจุดรับ) — สองแบบนี้สตาฟจัดการคนละทาง
 */
class StaffManifestJoinTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_groups_join_trip_passengers_apart_from_pickup_points(): void
    {
        [$staff, $schedule, $stop] = $this->makeAssignedSchedule();

        $this->makeBooking($schedule, pickupPointId: $stop->id, name: 'ผู้โดยสารขึ้นรถ');
        $this->makeBooking($schedule, isJoinTrip: true, name: 'ผู้จอยทริป');

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk();

        $groups = $response->json('data.pickup_groups');
        $this->assertCount(2, $groups, 'จอยทริปต้องแยกกลุ่มออกจากจุดรับ');

        // จุดรับจริงมาก่อน (sort_order) แล้วค่อยกลุ่มจอยทริป
        $this->assertSame($stop->id, $groups[0]['id']);
        $this->assertFalse($groups[0]['is_join_trip']);
        $this->assertSame('ผู้โดยสารขึ้นรถ', $groups[0]['passengers'][0]['full_name']);
        $this->assertFalse($groups[0]['passengers'][0]['is_join_trip']);

        $this->assertNull($groups[1]['id'], 'กลุ่มจอยทริปไม่ใช่จุดรับจริง จึงไม่มี id');
        $this->assertTrue($groups[1]['is_join_trip']);
        $this->assertSame('จอยทริป (ไม่มีจุดขึ้นรถ)', $groups[1]['label']);
        $this->assertNull($groups[1]['map_url']);
        $this->assertSame('ผู้จอยทริป', $groups[1]['passengers'][0]['full_name']);
        $this->assertTrue($groups[1]['passengers'][0]['is_join_trip']);
    }

    public function test_manifest_summary_and_booking_rows_split_join_trip_from_regular(): void
    {
        [$staff, $schedule, $stop] = $this->makeAssignedSchedule();

        $this->makeBooking($schedule, pickupPointId: $stop->id, passengerCount: 2);
        $this->makeBooking($schedule, isJoinTrip: true, passengerCount: 3);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->assertJsonPath('data.summary.passengers', 5)
            ->assertJsonPath('data.summary.regular_passengers', 2)
            ->assertJsonPath('data.summary.join_trip_passengers', 3);

        $bookings = collect($response->json('data.bookings'));
        $this->assertTrue($bookings->contains(fn ($b) => $b['is_join_trip'] === true));
        $this->assertTrue($bookings->contains(fn ($b) => $b['is_join_trip'] === false));
    }

    public function test_join_trip_passenger_keeps_the_pickup_point_when_one_is_assigned(): void
    {
        [$staff, $schedule, $stop] = $this->makeAssignedSchedule();

        // แอดมินใส่จุดรับให้คนจอยทริปเป็นกรณีพิเศษ — ต้องอยู่ในกลุ่มจุดรับนั้น
        // แต่ยังติดป้ายว่าเป็นจอยทริปอยู่
        $this->makeBooking(
            $schedule,
            pickupPointId: $stop->id,
            isJoinTrip: true,
            name: 'จอยแต่มีจุดรับ',
        );

        $groups = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->json('data.pickup_groups');

        $this->assertCount(1, $groups);
        $this->assertSame($stop->id, $groups[0]['id']);
        $this->assertFalse($groups[0]['is_join_trip']);
        $this->assertTrue($groups[0]['passengers'][0]['is_join_trip']);
    }

    public function test_my_schedules_separates_join_trip_from_missing_pickup(): void
    {
        [$staff, $schedule] = $this->makeAssignedSchedule();

        $this->makeBooking($schedule, isJoinTrip: true, name: 'ผู้จอยทริป');
        $this->makeBooking($schedule, name: 'ไม่ได้เลือกจุดรับ');

        $payload = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/staff/schedules/my')
            ->assertOk()
            ->json('data.schedules.0');

        $this->assertSame(1, $payload['join_trip_count']);
        $this->assertSame('ผู้จอยทริป', $payload['join_trip_passengers'][0]['name']);
        $this->assertTrue($payload['join_trip_passengers'][0]['is_join_trip']);

        $this->assertSame(1, $payload['no_pickup_count']);
        $this->assertSame('ไม่ได้เลือกจุดรับ', $payload['no_pickup_passengers'][0]['name']);
        $this->assertFalse($payload['no_pickup_passengers'][0]['is_join_trip']);
    }

    /** @return array{0: User, 1: TripSchedule, 2: SchedulePickupPoint} */
    private function makeAssignedSchedule(): array
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $trip = Trip::create([
            'title' => 'Join Trip Manifest',
            'slug' => 'join-trip-manifest-'.uniqid(),
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
            'join_trip_enabled' => true,
        ]);

        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $stop = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี',
            'price' => 0,
            'sort_order' => 1,
        ]);

        return [$staff, $schedule, $stop];
    }

    private function makeBooking(
        TripSchedule $schedule,
        ?int $pickupPointId = null,
        bool $isJoinTrip = false,
        int $passengerCount = 1,
        string $name = 'ผู้โดยสาร',
    ): Booking {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'pickup_point_id' => $pickupPointId,
            'is_join_trip' => $isJoinTrip,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        for ($i = 0; $i < $passengerCount; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => $passengerCount > 1 ? $name.' '.($i + 1) : $name,
                'phone' => '0800000000',
            ]);
        }

        return $booking;
    }
}
