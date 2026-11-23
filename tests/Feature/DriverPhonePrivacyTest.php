<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * เบอร์คนขับเป็นข้อมูลส่วนตัว — หน้าทริป/รายการรอบเปิดให้ใครก็ได้ดู จึงต้องไม่พกเบอร์ไปด้วย
 * ลูกค้าเห็นเบอร์ได้จากใบจองของตัวเองเท่านั้น
 */
class DriverPhonePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0899998888';

    private Trip $trip;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $vehicle = Vehicle::create([
            'name' => 'ตู้ 1', 'license_plate' => 'กข-1234', 'type' => 'van',
            'capacity' => 12, 'status' => 'active',
            'driver_name' => 'พี่หนุ่ม', 'driver_phone' => self::PHONE,
        ]);
        $this->trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'driver-phone-'.uniqid(), 'type' => 'trekking', 'location' => 'เลย',
            'difficulty' => 'easy', 'duration_days' => 2, 'max_participants' => 20,
            'price_per_person' => 3000, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(31)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    public function test_public_trip_and_schedule_endpoints_do_not_leak_the_driver_phone(): void
    {
        foreach ([
            "/api/v1/trips/{$this->trip->slug}/schedules",
            "/api/v1/trips/{$this->trip->slug}",
            "/api/v1/schedules/{$this->schedule->id}",
            '/api/v1/vehicles',
        ] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertStringNotContainsString(self::PHONE, $response->getContent(), $url);
        }

        // ชื่อคนขับยังแสดงได้ตามเดิม (หน้าจองบนเว็บโชว์ "พนักงานขับรถ")
        $this->getJson("/api/v1/trips/{$this->trip->slug}/schedules")
            ->assertJsonPath('data.0.vehicle.driver_name', 'พี่หนุ่ม')
            ->assertJsonPath('data.0.vehicle.driver_phone', null);
    }

    public function test_the_booking_owner_still_sees_the_driver_phone(): void
    {
        $user = User::factory()->create();
        /** @var Booking $booking */
        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $this->schedule->id,
            passengers: [['name' => 'ผู้เดินทาง', 'phone' => '0812345678']],
            verifySeatLocks: false,
        );

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/bookings/'.$booking->booking_ref)
            ->assertOk()
            ->assertJsonPath('data.schedule.vehicle.driver_phone', self::PHONE);
    }
}
