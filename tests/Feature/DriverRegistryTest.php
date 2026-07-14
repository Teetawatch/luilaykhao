<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
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
}
