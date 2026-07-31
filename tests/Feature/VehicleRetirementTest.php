<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleRetirementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->trip = Trip::create([
            'title' => 'ทริปทดสอบ', 'slug' => 'trip-retire', 'type' => 'trekking', 'location' => 'เชียงใหม่',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 10,
            'price_per_person' => 1000, 'status' => 'active',
        ]);
    }

    private function makeVehicle(string $name = 'รถตู้ 1'): Vehicle
    {
        return Vehicle::create(['name' => $name, 'type' => 'van', 'capacity' => 12]);
    }

    private function makeSchedule(Vehicle $vehicle, string $date, string $status = 'open'): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $this->trip->id, 'vehicle_id' => $vehicle->id,
            'departure_date' => $date, 'return_date' => $date,
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => $status,
        ]);
    }

    private function vehiclePayload(int $id): array
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/vehicles?per_page=100')
            ->assertOk();

        return collect($res->json('data'))->firstWhere('id', $id);
    }

    public function test_vehicle_with_only_past_rounds_is_reported_as_retired(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, now('Asia/Bangkok')->subDays(30)->toDateString());
        $this->makeSchedule($vehicle, now('Asia/Bangkok')->subDays(5)->toDateString());

        $payload = $this->vehiclePayload($vehicle->id);

        $this->assertTrue($payload['is_retired']);
        $this->assertSame(2, $payload['schedules_count']);
        $this->assertSame(0, $payload['upcoming_schedules_count']);
        $this->assertStringStartsWith(
            now('Asia/Bangkok')->subDays(5)->toDateString(),
            $payload['last_departure_date'],
        );
    }

    public function test_vehicle_with_a_round_today_or_later_stays_active(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, now('Asia/Bangkok')->subDays(10)->toDateString());
        $this->makeSchedule($vehicle, now('Asia/Bangkok')->toDateString());

        $payload = $this->vehiclePayload($vehicle->id);

        $this->assertFalse($payload['is_retired']);
        $this->assertSame(1, $payload['upcoming_schedules_count']);
    }

    public function test_brand_new_vehicle_without_any_round_is_not_retired(): void
    {
        $vehicle = $this->makeVehicle('รถตู้ใหม่');

        $payload = $this->vehiclePayload($vehicle->id);

        $this->assertFalse($payload['is_retired']);
        $this->assertSame(0, $payload['schedules_count']);
        $this->assertNull($payload['last_departure_date']);
    }

    public function test_cancelled_future_round_does_not_keep_a_vehicle_active(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, now('Asia/Bangkok')->addDays(20)->toDateString(), 'cancelled');

        $this->assertTrue($this->vehiclePayload($vehicle->id)['is_retired']);
    }

    public function test_status_filter_splits_active_and_retired_vehicles(): void
    {
        $active = $this->makeVehicle('รถใช้งานอยู่');
        $this->makeSchedule($active, now('Asia/Bangkok')->addDays(3)->toDateString());

        $retired = $this->makeVehicle('รถเลิกใช้แล้ว');
        $this->makeSchedule($retired, now('Asia/Bangkok')->subDays(3)->toDateString());

        $unused = $this->makeVehicle('รถยังไม่เคยใช้');

        $activeIds = collect(
            $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/v1/admin/vehicles?status=active&per_page=100')
                ->assertOk()->json('data')
        )->pluck('id');

        $retiredIds = collect(
            $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/v1/admin/vehicles?status=retired&per_page=100')
                ->assertOk()->json('data')
        )->pluck('id');

        $this->assertEqualsCanonicalizing([$active->id, $unused->id], $activeIds->all());
        $this->assertSame([$retired->id], $retiredIds->all());
    }

    public function test_retired_vehicle_can_be_deleted_permanently(): void
    {
        $vehicle = $this->makeVehicle();
        $past = $this->makeSchedule($vehicle, now('Asia/Bangkok')->subDays(9)->toDateString());

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
            ->assertOk();

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
        // รอบเก่ายังอยู่ แต่ไม่ผูกกับรถแล้ว
        $this->assertNull($past->fresh()->vehicle_id);
    }

    public function test_vehicle_with_an_upcoming_round_cannot_be_deleted(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule($vehicle, now('Asia/Bangkok')->addDays(2)->toDateString());

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }
}
