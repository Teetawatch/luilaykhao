<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleTypeTest extends TestCase
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

    public function test_admin_can_register_a_bus(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/vehicles', [
            'name' => 'รถบัส 40 ที่นั่ง',
            'type' => 'bus',
            'capacity' => 40,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'bus');

        $this->assertDatabaseHas('vehicles', ['name' => 'รถบัส 40 ที่นั่ง', 'type' => 'bus']);
    }

    public function test_vehicle_type_outside_the_registry_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/vehicles', [
            'name' => 'เครื่องบินเช่าเหมาลำ',
            'type' => 'flight',
            'capacity' => 180,
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_existing_van_can_be_switched_to_a_bus(): void
    {
        $vehicle = Vehicle::create(['name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 12]);

        $this->actingAs($this->admin)->putJson("/api/v1/admin/vehicles/{$vehicle->id}", [
            'name' => 'รถบัส 1',
            'type' => 'bus',
            'capacity' => 45,
        ])->assertOk()->assertJsonPath('data.type', 'bus');

        $this->assertSame('bus', $vehicle->fresh()->type);
    }
}
