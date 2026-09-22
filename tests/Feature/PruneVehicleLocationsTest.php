<?php

namespace Tests\Feature;

use App\Jobs\PruneVehicleLocationsJob;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตารางพิกัดรถโตเร็วที่สุดในระบบและไม่เคยมีอะไรลบมันเลย — งานนี้คือคนเก็บกวาด
 */
class PruneVehicleLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_recent_positions_and_drops_old_ones(): void
    {
        $vehicle = $this->vehicle();

        $this->pin($vehicle, daysAgo: 1);
        $this->pin($vehicle, daysAgo: 89);
        $old = $this->pin($vehicle, daysAgo: 91);
        $ancient = $this->pin($vehicle, daysAgo: 400);

        $deleted = (new PruneVehicleLocationsJob)->handle();

        $this->assertSame(2, $deleted);
        $this->assertSame(2, VehicleLocation::count());
        $this->assertDatabaseMissing('vehicle_locations', ['id' => $old->id]);
        $this->assertDatabaseMissing('vehicle_locations', ['id' => $ancient->id]);
    }

    public function test_it_clears_a_backlog_bigger_than_one_chunk(): void
    {
        $vehicle = $this->vehicle();

        // ของค้างที่สะสมมาก่อนมีงานลบ — ต้องหมดในรอบเดียว ไม่ใช่ค้างไว้ก้อนหนึ่ง
        $rows = PruneVehicleLocationsJob::CHUNK + 250;
        $now = now()->subDays(120);

        VehicleLocation::insert(
            collect(range(1, $rows))->map(fn (int $i) => [
                'vehicle_id' => $vehicle->id,
                'latitude' => 13.75,
                'longitude' => 100.5,
                'recorded_at' => $now->copy()->addSeconds($i),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all(),
        );

        $deleted = (new PruneVehicleLocationsJob)->handle();

        $this->assertSame($rows, $deleted);
        $this->assertSame(0, VehicleLocation::count());
    }

    public function test_the_command_can_look_before_it_deletes(): void
    {
        $vehicle = $this->vehicle();
        $this->pin($vehicle, daysAgo: 200);

        $this->artisan('tracking:prune', ['--dry-run' => true])
            ->expectsOutputToContain('เก่ากว่า 90 วัน: 1 แถว')
            ->assertSuccessful();

        $this->assertSame(1, VehicleLocation::count(), 'โหมดลองดูต้องไม่ลบอะไร');

        $this->artisan('tracking:prune')->assertSuccessful();

        $this->assertSame(0, VehicleLocation::count());
    }

    public function test_the_retention_window_can_be_narrowed_for_a_one_off_clean(): void
    {
        $vehicle = $this->vehicle();
        $this->pin($vehicle, daysAgo: 10);
        $this->pin($vehicle, daysAgo: 40);

        $this->artisan('tracking:prune', ['--days' => 30])->assertSuccessful();

        $this->assertSame(1, VehicleLocation::count());
    }

    public function test_a_nonsense_retention_is_refused_rather_than_wiping_everything(): void
    {
        $vehicle = $this->vehicle();
        $this->pin($vehicle, daysAgo: 1);

        $this->artisan('tracking:prune', ['--days' => 0])->assertFailed();

        $this->assertSame(1, VehicleLocation::count());
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'name' => 'รถตู้คันที่ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'ฮก 8899',
        ]);
    }

    private function pin(Vehicle $vehicle, int $daysAgo): VehicleLocation
    {
        return VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now()->subDays($daysAgo),
        ]);
    }
}
