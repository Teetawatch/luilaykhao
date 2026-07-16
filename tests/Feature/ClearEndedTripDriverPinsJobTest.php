<?php

namespace Tests\Feature;

use App\Jobs\ClearEndedTripDriverPinsJob;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleDriverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClearEndedTripDriverPinsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    }

    private function makeVehicle(string $name = 'รถตู้ 1'): Vehicle
    {
        return Vehicle::create([
            'name' => $name, 'type' => 'van', 'capacity' => 12,
            'license_plate' => '1กก1234', 'driver_name' => 'สมชาย', 'driver_phone' => '081-111-2222',
        ]);
    }

    private function withPin(Vehicle $vehicle, string $pin = '4521'): Vehicle
    {
        app(VehicleDriverService::class)->setPin($vehicle, $pin);

        return $vehicle->refresh();
    }

    private function schedule(Vehicle $vehicle, string $returnDate, ?string $departureDate = null): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => $departureDate ?? $returnDate,
            'return_date' => $returnDate,
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    public function test_clears_pin_of_a_vehicle_whose_round_has_ended(): void
    {
        $vehicle = $this->withPin($this->makeVehicle());
        $schedule = $this->schedule($vehicle, now()->subDay()->toDateString());

        $this->assertTrue($vehicle->hasDriverPin());

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));

        $this->assertFalse($vehicle->refresh()->hasDriverPin());
        $this->assertNotNull($schedule->refresh()->driver_pin_cleared_at);
    }

    public function test_keeps_pin_of_a_round_that_has_not_ended_yet(): void
    {
        $vehicle = $this->withPin($this->makeVehicle());
        $schedule = $this->schedule($vehicle, now()->addDays(2)->toDateString());

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));

        $this->assertTrue($vehicle->refresh()->hasDriverPin());
        $this->assertNull($schedule->refresh()->driver_pin_cleared_at);
    }

    public function test_keeps_pin_of_a_round_that_ends_today(): void
    {
        $vehicle = $this->withPin($this->makeVehicle());
        $this->schedule($vehicle, now('Asia/Bangkok')->toDateString());

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));

        $this->assertTrue($vehicle->refresh()->hasDriverPin());
    }

    public function test_uses_return_date_not_departure_date_for_a_multi_day_round(): void
    {
        $vehicle = $this->withPin($this->makeVehicle());

        // ออกเดินทางไปแล้ว 2 วัน แต่ยังไม่กลับ — คนขับยังต้องใช้ PIN อยู่
        $this->schedule(
            $vehicle,
            now('Asia/Bangkok')->addDay()->toDateString(),
            now('Asia/Bangkok')->subDays(2)->toDateString(),
        );

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));

        $this->assertTrue($vehicle->refresh()->hasDriverPin());
    }

    public function test_an_already_cleared_old_round_does_not_wipe_the_pin_set_for_the_next_round(): void
    {
        $vehicle = $this->withPin($this->makeVehicle());
        $this->schedule($vehicle, now()->subDays(3)->toDateString());

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));
        $this->assertFalse($vehicle->refresh()->hasDriverPin());

        // แอดมินตั้งรหัสเดิมอีกครั้งสำหรับรอบถัดไป — รอบเก่าต้องไม่ย้อนมาล้างซ้ำ
        $vehicle = $this->withPin($vehicle);
        $this->schedule($vehicle, now()->addDays(3)->toDateString());

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));

        $this->assertTrue($vehicle->refresh()->hasDriverPin());
    }

    public function test_pin_is_reusable_on_another_vehicle_once_the_round_has_ended(): void
    {
        $old = $this->withPin($this->makeVehicle('รถตู้ เก่า'), '4521');
        $this->schedule($old, now()->subDay()->toDateString());

        $new = $this->makeVehicle('รถตู้ ใหม่');

        // ก่อนล้าง: รหัสเดิมถูกกันไว้ด้วยบัญชีคนขับของรถคันเก่า
        try {
            app(VehicleDriverService::class)->setPin($new, '4521');
            $this->fail('setPin ควรปฏิเสธรหัสที่ยังค้างอยู่กับรถคันอื่น');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ถูกใช้กับคนขับคนอื่นแล้ว', $e->getMessage());
        }

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));

        // หลังล้าง: รหัสเดิมกลับมาใช้กับรถคันใหม่ได้
        app(VehicleDriverService::class)->setPin($new, '4521');
        $this->assertTrue($new->refresh()->hasDriverPin());
    }

    public function test_does_not_touch_a_staff_pin_that_is_not_a_vehicle_driver(): void
    {
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff = User::factory()->create(['driver_pin_hash' => bcrypt('9999')]);
        $staff->assignRole('staff');

        $vehicle = $this->withPin($this->makeVehicle());
        $this->schedule($vehicle, now()->subDay()->toDateString());

        (new ClearEndedTripDriverPinsJob)->handle(app(VehicleDriverService::class));

        $this->assertNotNull($staff->refresh()->driver_pin_hash);
    }
}
