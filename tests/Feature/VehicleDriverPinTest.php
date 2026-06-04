<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleDriverPinTest extends TestCase
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

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 12,
            'license_plate' => '1กก1234', 'driver_name' => 'สมชาย', 'driver_phone' => '081-111-2222',
        ]);
    }

    public function test_admin_sets_driver_pin_and_creates_hidden_driver_account(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk()
            ->assertJsonPath('data.has_driver_pin', true);

        $vehicle->refresh();
        $this->assertNotNull($vehicle->driver_user_id);

        $driver = User::find($vehicle->driver_user_id);
        $this->assertTrue($driver->hasRole('driver'));
        $this->assertSame('081-111-2222', $driver->phone);
        $this->assertNotNull($driver->driver_pin_hash);

        // บัญชีคนขับต้องไม่โผล่ในเมนูผู้ใช้งานระบบ
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonMissing(['id' => $driver->id]);
    }

    public function test_driver_can_pin_login_and_see_assigned_vehicle_schedule(): void
    {
        $vehicle = $this->makeVehicle();

        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-pin', 'type' => 'trekking', 'location' => 'X',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 10,
            'price_per_person' => 1000, 'status' => 'active',
        ]);
        TripSchedule::create([
            'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id,
            'departure_date' => today()->toDateString(), 'return_date' => today()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk();

        $res = $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '4521'])->assertOk();
        $this->assertNotEmpty($res->json('data.token'));
        $this->assertCount(1, $res->json('data.schedules'));
        $this->assertSame($vehicle->id, $res->json('data.schedules.0.vehicle.id'));
    }

    public function test_duplicate_pin_is_rejected(): void
    {
        $v1 = $this->makeVehicle();
        $v2 = Vehicle::create(['name' => 'รถตู้ 2', 'type' => 'van', 'capacity' => 12, 'driver_phone' => '082-000-0000']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$v1->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$v2->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertStatus(422);
    }

    public function test_clear_pin_disables_login(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}/driver-pin")
            ->assertOk()
            ->assertJsonPath('data.has_driver_pin', false);

        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '4521'])->assertStatus(401);
    }

    public function test_pin_must_be_four_to_eight_digits(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}/driver-pin", ['driver_pin' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('driver_pin');
    }
}
