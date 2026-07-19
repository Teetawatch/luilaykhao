<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าติดตามรถต้องแสดงเฉพาะรถของรอบที่ "ยังเดินทางไม่จบ" — รถที่จบทริปไปแล้ว
 * ต้องหลุดออกจากแผนที่ ไม่ค้างอยู่ด้วยพิกัดจุดสุดท้ายของรอบเก่า
 */
class CurrentVehicleLocationsTest extends TestCase
{
    use RefreshDatabase;

    private function makeVehicle(string $name = 'Van 1'): Vehicle
    {
        return Vehicle::create([
            'name' => $name,
            'type' => 'van',
            'license_plate' => 'กข-'.rand(1000, 9999),
            'capacity' => 10,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Vehicle $vehicle, array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Tracking Trip', 'slug' => 'tracking-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 1500, 'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ], $overrides));
    }

    private function ping(Vehicle $vehicle, string $recordedAt, float $lat = 18.78, float $lng = 98.98): VehicleLocation
    {
        return VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'speed' => 40,
            'heading' => 90,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function test_vehicle_whose_trip_has_ended_is_not_listed(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, [
            'departure_date' => now('Asia/Bangkok')->subDays(30)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(29)->toDateString(),
        ]);
        $this->ping($vehicle, now()->subDays(29)->toDateTimeString());

        $this->getJson('/api/v1/tracking/current')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_vehicle_on_a_round_in_progress_is_listed_with_its_trip(): void
    {
        $vehicle = $this->makeVehicle();
        $schedule = $this->makeSchedule($vehicle);
        $this->ping($vehicle, now()->subMinute()->toDateTimeString());

        $this->getJson('/api/v1/tracking/current')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.0.schedule_id', $schedule->id)
            ->assertJsonPath('data.0.trip_title', 'Tracking Trip');
    }

    public function test_multi_day_round_still_listed_on_its_middle_day(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, [
            'departure_date' => now('Asia/Bangkok')->subDay()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
        ]);
        $this->ping($vehicle, now()->subMinutes(2)->toDateTimeString());

        $this->getJson('/api/v1/tracking/current')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trip_title', 'Tracking Trip');
    }

    public function test_stale_fix_from_a_previous_round_is_not_shown_as_the_current_position(): void
    {
        $vehicle = $this->makeVehicle();
        // รอบเก่าเมื่อเดือนที่แล้ว ทิ้งพิกัดสุดท้ายไว้
        $this->ping($vehicle, now()->subDays(30)->toDateTimeString(), 13.75, 100.50);
        // วันนี้มีรอบใหม่ แต่คนขับยังไม่เปิดแอปส่ง GPS
        $this->makeSchedule($vehicle);

        $this->getJson('/api/v1/tracking/current')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // รถต้องขึ้นในลิสต์ (มีงานวันนี้) แต่ต้องไม่มีพิกัดของรอบเก่า
            ->assertJsonPath('data.0.latitude', null)
            ->assertJsonPath('data.0.recorded_at', null);
    }

    public function test_cancelled_round_is_not_tracked(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, ['status' => 'cancelled']);
        $this->ping($vehicle, now()->subMinute()->toDateTimeString());

        $this->getJson('/api/v1/tracking/current')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_vehicle_departing_tonight_for_tomorrows_trip_is_tracked(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, [
            'departure_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'departs_at' => now('Asia/Bangkok')->setTime(22, 30)->format('Y-m-d H:i:s'),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
        ]);
        $this->ping($vehicle, now()->toDateTimeString());

        $this->getJson('/api/v1/tracking/current')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_vehicle_with_no_round_at_all_is_not_listed(): void
    {
        $vehicle = $this->makeVehicle();
        $this->ping($vehicle, now()->subMinute()->toDateTimeString());

        $this->getJson('/api/v1/tracking/current')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
