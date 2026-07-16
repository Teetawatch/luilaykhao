<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverLoginCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DriverQrLoginTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function driverUser(): User
    {
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
        $driver = User::factory()->create(['driver_pin_hash' => Hash::make('1234')]);
        $driver->assignRole('driver');

        return $driver;
    }

    private function vehicleWithDriver(User $driver): Vehicle
    {
        return Vehicle::create([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'capacity' => 12,
            'license_plate' => 'กข 1234',
            'driver_user_id' => $driver->id,
        ]);
    }

    public function test_admin_can_issue_a_login_qr_for_a_vehicle_driver(): void
    {
        Sanctum::actingAs($this->admin());
        $vehicle = $this->vehicleWithDriver($this->driverUser());

        $response = $this->postJson("/api/v1/admin/vehicles/{$vehicle->id}/login-qr");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['code', 'expires_at', 'expires_at_label', 'expires_in_seconds', 'driver_name'],
            ])
            // Admins often send the QR ahead of time, so it must stay valid for a day.
            ->assertJsonPath('data.expires_in_seconds', 24 * 3600);

        $this->assertNotEmpty($response->json('data.code'));
    }

    public function test_issuing_fails_when_the_vehicle_has_no_driver_account(): void
    {
        Sanctum::actingAs($this->admin());
        $vehicle = Vehicle::create([
            'name' => 'รถตู้ไร้คนขับ',
            'type' => 'van',
            'capacity' => 12,
        ]);

        $this->postJson("/api/v1/admin/vehicles/{$vehicle->id}/login-qr")
            ->assertStatus(422);
    }

    public function test_driver_can_exchange_the_code_for_a_token(): void
    {
        $driver = $this->driverUser();
        $code = app(DriverLoginCodeService::class)->issue($driver)['code'];

        $response = $this->postJson('/api/v1/driver/qr-login', ['code' => $code]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $driver->id)
            ->assertJsonStructure(['data' => ['token', 'user', 'schedules']]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $driver = $this->driverUser();
        $code = app(DriverLoginCodeService::class)->issue($driver)['code'];

        $this->postJson('/api/v1/driver/qr-login', ['code' => $code])->assertOk();
        // Photographing the QR must not grant a second login.
        $this->postJson('/api/v1/driver/qr-login', ['code' => $code])->assertStatus(401);
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        $this->postJson('/api/v1/driver/qr-login', ['code' => 'not-a-real-code'])
            ->assertStatus(401);
    }

    public function test_code_is_rejected_when_the_driver_role_was_revoked(): void
    {
        $driver = $this->driverUser();
        $code = app(DriverLoginCodeService::class)->issue($driver)['code'];

        $driver->removeRole('driver');

        $this->postJson('/api/v1/driver/qr-login', ['code' => $code])
            ->assertStatus(403);
    }
}
