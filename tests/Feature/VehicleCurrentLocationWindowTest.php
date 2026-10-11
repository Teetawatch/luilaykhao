<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\VehicleLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * หน้าติดตามรถในแอป (tracking/current/{vehicleId}) และลิงก์แชร์ (/track/{token})
 * ต้องไม่หยิบพิกัดของทริปก่อนหน้ามาแสดงเป็นตำแหน่งสด — เช้าวันเดินทางก่อนสตาฟ
 * เปิดแอป รถคันเดียวกันยังมีจุดสุดท้ายของเมื่อวานค้างอยู่ในฐานข้อมูล
 */
class VehicleCurrentLocationWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'name' => 'Van 1',
            'type' => 'van',
            'license_plate' => 'กข-'.rand(1000, 9999),
            'capacity' => 10,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Vehicle $vehicle, array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Window Trip', 'slug' => 'window-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1500, 'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
        ], $overrides));
    }

    private function ping(Vehicle $vehicle, Carbon $recordedAt, float $lat = 18.78): VehicleLocation
    {
        return VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'latitude' => $lat,
            'longitude' => 98.98,
            'speed' => 40,
            'heading' => 90,
            'recorded_at' => $recordedAt->copy()->utc(),
        ]);
    }

    private function makeBooking(TripSchedule $schedule): Booking
    {
        $booking = Booking::create([
            'booking_ref' => 'LLK-WIN-'.strtoupper(uniqid()),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'payment_type' => 'full',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
        $booking->ensureShareToken();

        return $booking;
    }

    public function test_yesterdays_last_fix_is_not_todays_position(): void
    {
        // 05:00 เช้าวันเดินทาง สตาฟยังไม่เปิดแอป
        Carbon::setTestNow(Carbon::parse('2026-10-12 05:00', 'Asia/Bangkok'));
        $vehicle = $this->makeVehicle();

        $this->makeSchedule($vehicle, [
            'departure_date' => '2026-10-11', 'return_date' => '2026-10-11',
        ]);
        $today = $this->makeSchedule($vehicle, [
            'departure_date' => '2026-10-12', 'return_date' => '2026-10-12',
            'departs_at' => '2026-10-12 06:00:00',
        ]);
        // จุดส่งของทริปเมื่อวาน 16:00
        $this->ping($vehicle, Carbon::parse('2026-10-11 16:00', 'Asia/Bangkok'), 13.75);

        $this->getJson("/api/v1/tracking/current/{$vehicle->id}?schedule_id={$today->id}")
            ->assertNotFound();

        // แอปรุ่นเก่าที่ไม่ส่ง schedule_id ก็ต้องไม่ได้จุดเก่าเหมือนกัน
        $this->getJson("/api/v1/tracking/current/{$vehicle->id}")
            ->assertNotFound();
    }

    public function test_fix_from_this_round_is_returned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 05:30', 'Asia/Bangkok'));
        $vehicle = $this->makeVehicle();
        $today = $this->makeSchedule($vehicle, [
            'departure_date' => '2026-10-12', 'return_date' => '2026-10-12',
            'departs_at' => '2026-10-12 06:00:00',
        ]);
        $this->ping($vehicle, Carbon::parse('2026-10-11 16:00', 'Asia/Bangkok'), 13.75);
        $this->ping($vehicle, Carbon::parse('2026-10-12 05:29', 'Asia/Bangkok'), 18.80);

        $this->getJson("/api/v1/tracking/current/{$vehicle->id}?schedule_id={$today->id}")
            ->assertOk()
            ->assertJsonPath('data.latitude', 18.8)
            ->assertJsonPath('data.schedule_id', $today->id);
    }

    public function test_night_departure_counts_fixes_from_the_evening_before(): void
    {
        // รถออก 22:30 คืนก่อนวันทริป — สตาฟส่งพิกัดตั้งแต่ 22:00 เวลาไทย
        Carbon::setTestNow(Carbon::parse('2026-10-11 22:05', 'Asia/Bangkok'));
        $vehicle = $this->makeVehicle();
        $round = $this->makeSchedule($vehicle, [
            'departure_date' => '2026-10-12', 'return_date' => '2026-10-13',
            'departs_at' => '2026-10-11 22:30:00',
        ]);
        $this->ping($vehicle, Carbon::parse('2026-10-11 22:00', 'Asia/Bangkok'), 18.81);

        $this->getJson("/api/v1/tracking/current/{$vehicle->id}?schedule_id={$round->id}")
            ->assertOk()
            ->assertJsonPath('data.latitude', 18.81);
    }

    public function test_tracking_since_is_real_utc_time(): void
    {
        $vehicle = $this->makeVehicle();
        $round = $this->makeSchedule($vehicle, [
            'departure_date' => '2026-10-12', 'departs_at' => '2026-10-12 06:00:00',
        ]);

        // 6 ชม. ก่อนเที่ยงคืนวันทริป (เวลาไทย) = 18:00 ไทย = 11:00 UTC
        $this->assertSame(
            '2026-10-11 11:00:00',
            app(VehicleLocationService::class)->trackingSince($round)->format('Y-m-d H:i:s'),
        );
    }

    public function test_schedule_id_of_another_vehicle_is_ignored(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 07:00', 'Asia/Bangkok'));
        $vehicle = $this->makeVehicle();
        $other = $this->makeVehicle();
        $this->makeSchedule($vehicle, ['departure_date' => '2026-10-12', 'return_date' => '2026-10-12']);
        $foreign = $this->makeSchedule($other, ['departure_date' => '2026-09-01', 'return_date' => '2026-09-01']);
        $this->ping($vehicle, Carbon::parse('2026-10-12 06:59', 'Asia/Bangkok'), 18.82);

        // รอบของรถคันอื่นใช้ขยายกรอบเวลาให้รถคันนี้ไม่ได้ — ถอยไปใช้รอบของรถคันนี้เอง
        $this->getJson("/api/v1/tracking/current/{$vehicle->id}?schedule_id={$foreign->id}")
            ->assertOk()
            ->assertJsonPath('data.latitude', 18.82);
    }

    public function test_shared_link_does_not_show_yesterdays_fix(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 05:00', 'Asia/Bangkok'));
        $vehicle = $this->makeVehicle();
        $today = $this->makeSchedule($vehicle, [
            'departure_date' => '2026-10-12', 'return_date' => '2026-10-12',
            'departs_at' => '2026-10-12 06:00:00',
        ]);
        $this->ping($vehicle, Carbon::parse('2026-10-11 16:00', 'Asia/Bangkok'), 13.75);
        $booking = $this->makeBooking($today);

        $res = $this->getJson('/api/v1/track/'.$booking->share_token)->assertOk();

        $this->assertNull($res->json('data.vehicle'));
        $this->assertSame('รถยังไม่เริ่มส่งตำแหน่ง โปรดติดตามอีกครั้ง', $res->json('data.message'));

        // พอสตาฟเริ่มส่ง ลิงก์ก็เห็นรถ
        $this->ping($vehicle, Carbon::parse('2026-10-12 04:59', 'Asia/Bangkok'), 18.83);

        $this->getJson('/api/v1/track/'.$booking->share_token)
            ->assertOk()
            ->assertJsonPath('data.vehicle.lat', 18.83);
    }
}
