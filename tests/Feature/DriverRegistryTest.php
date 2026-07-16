<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleDriverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DriverRegistryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_admin_can_create_a_driver_in_the_registry(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/drivers', [
                'name' => 'สมชาย ใจดี',
                'phone' => '081-111-2222',
                'license_number' => 'บ1234567',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'สมชาย ใจดี')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('drivers', ['name' => 'สมชาย ใจดี', 'phone' => '081-111-2222']);
    }

    public function test_assigning_a_driver_to_a_vehicle_mirrors_the_snapshot(): void
    {
        $driver = Driver::create(['name' => 'สมหญิง', 'phone' => '089-999-8888', 'photo' => 'https://cdn/x.jpg']);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/vehicles', [
                'name' => 'รถตู้ VIP-01', 'type' => 'van', 'capacity' => 12,
                'license_plate' => '1กก1234', 'driver_id' => $driver->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.driver_id', $driver->id)
            ->assertJsonPath('data.driver_name', 'สมหญิง');

        $vehicle = Vehicle::find($res->json('data.id'));
        $this->assertSame('สมหญิง', $vehicle->driver_name);
        $this->assertSame('089-999-8888', $vehicle->driver_phone);
        $this->assertSame('https://cdn/x.jpg', $vehicle->driver_photo);
    }

    public function test_editing_a_driver_updates_snapshot_on_linked_vehicles(): void
    {
        $driver = Driver::create(['name' => 'เดิม', 'phone' => '081-000-0000']);
        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 12, 'driver_id' => $driver->id,
            'driver_name' => 'เดิม', 'driver_phone' => '081-000-0000',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/drivers/{$driver->id}", [
                'name' => 'ใหม่', 'phone' => '082-222-2222',
            ])
            ->assertOk();

        $vehicle->refresh();
        $this->assertSame('ใหม่', $vehicle->driver_name);
        $this->assertSame('082-222-2222', $vehicle->driver_phone);
    }

    public function test_cannot_delete_a_driver_still_linked_to_a_vehicle(): void
    {
        $driver = Driver::create(['name' => 'ผูกอยู่']);
        Vehicle::create(['name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 12, 'driver_id' => $driver->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
    }

    public function test_can_delete_an_unlinked_driver(): void
    {
        $driver = Driver::create(['name' => 'ว่าง']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/drivers/{$driver->id}")
            ->assertOk();

        $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
    }

    public function test_drivers_list_is_searchable(): void
    {
        Driver::create(['name' => 'อาทิตย์', 'phone' => '081-111-1111']);
        Driver::create(['name' => 'จันทร์', 'phone' => '082-222-2222']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/drivers?'.http_build_query(['search' => 'อาทิตย์']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'อาทิตย์');
    }

    public function test_driver_list_carries_full_vehicle_detail_and_usage_in_one_call(): void
    {
        $driver = Driver::create(['name' => 'สมชาย', 'phone' => '081-111-2222', 'license_number' => 'บ1234567']);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ VIP-01', 'type' => 'van', 'capacity' => 12,
            'license_plate' => '1กก1234', 'color' => 'ขาว', 'driver_id' => $driver->id,
        ]);
        app(VehicleDriverService::class)->setPin($vehicle, '4521');

        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1000, 'status' => 'active',
        ]);
        TripSchedule::create([
            'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id,
            'departure_date' => now()->subDays(5)->toDateString(),
            'return_date' => now()->subDays(4)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        TripSchedule::create([
            'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id,
            'departure_date' => now()->addDays(3)->toDateString(),
            'return_date' => now()->addDays(4)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/drivers')
            ->assertOk();

        $res->assertJsonPath('data.0.vehicles_count', 1)
            ->assertJsonPath('data.0.vehicles.0.license_plate', '1กก1234')
            ->assertJsonPath('data.0.vehicles.0.color', 'ขาว')
            ->assertJsonPath('data.0.vehicles.0.type', 'van')
            ->assertJsonPath('data.0.vehicles.0.capacity', 12)
            ->assertJsonPath('data.0.vehicles.0.has_driver_pin', true)
            ->assertJsonPath('data.0.upcoming_trips_count', 1);

        // ใช้งานล่าสุด = วันกลับของรอบที่ผ่านมาแล้ว
        $this->assertStringStartsWith(
            now()->subDays(4)->toDateString(),
            $res->json('data.0.last_trip_date'),
        );
    }

    public function test_an_unused_driver_reports_no_vehicles_and_no_trips(): void
    {
        Driver::create(['name' => 'คนขับว่าง']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/drivers')
            ->assertOk()
            ->assertJsonPath('data.0.vehicles_count', 0)
            ->assertJsonPath('data.0.vehicles', [])
            ->assertJsonPath('data.0.upcoming_trips_count', 0)
            ->assertJsonMissingPath('data.0.last_trip_date');
    }

    public function test_unlinked_only_filter_returns_just_the_deletable_drivers(): void
    {
        $linked = Driver::create(['name' => 'มีรถ']);
        Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 12,
            'license_plate' => '1กก1234', 'driver_id' => $linked->id,
        ]);
        Driver::create(['name' => 'ไม่มีรถ']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/drivers?unlinked_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'ไม่มีรถ');
    }

    public function test_drivers_are_searchable_by_licence_plate(): void
    {
        $driver = Driver::create(['name' => 'สมชาย']);
        Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 12,
            'license_plate' => '9กก9999', 'driver_id' => $driver->id,
        ]);
        Driver::create(['name' => 'คนอื่น']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/drivers?'.http_build_query(['search' => '9กก9999']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'สมชาย');
    }
}
