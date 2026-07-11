<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleInspectionTest extends TestCase
{
    use RefreshDatabase;

    private function staffOnSchedule(): array
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
        $vehicle = Vehicle::create([
            'name' => 'รถตู้ A',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'กข 1234',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
            'vehicle_id' => $vehicle->id,
        ]);
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return [$staff, $schedule];
    }

    /** All items ok → passed, no critical failure, vehicle captured. */
    public function test_stores_a_passing_inspection(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();

        $items = collect(VehicleInspection::ITEMS)
            ->map(fn ($i) => ['key' => $i['key'], 'ok' => true])
            ->all();

        $data = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/inspection", [
                'items' => $items,
                'note' => 'พร้อมออกเดินทาง',
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['passed']);
        $this->assertFalse($data['critical_failed']);
        $this->assertCount(count(VehicleInspection::ITEMS), $data['items']);
        $this->assertSame($staff->name, $data['inspected_by_name']);

        $this->assertDatabaseHas('vehicle_inspections', [
            'schedule_id' => $schedule->id,
            'vehicle_id' => $schedule->vehicle_id,
            'passed' => true,
            'critical_failed' => false,
        ]);
    }

    /** A failed critical item flags critical_failed and not passed. */
    public function test_failing_a_critical_item_flags_the_departure(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();

        $items = collect(VehicleInspection::ITEMS)
            ->map(fn ($i) => ['key' => $i['key'], 'ok' => $i['key'] !== 'brakes'])
            ->all();

        $data = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/inspection", [
                'items' => $items,
            ])
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['passed']);
        $this->assertTrue($data['critical_failed']);

        $brakes = collect($data['items'])->firstWhere('key', 'brakes');
        $this->assertFalse($brakes['ok']);
        $this->assertTrue($brakes['critical']);
    }

    /** Labels/critical flags come from the server template, not the request. */
    public function test_item_labels_are_trusted_from_server(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/inspection", [
                'items' => [
                    ['key' => 'tires', 'ok' => true, 'label' => 'HACKED'],
                ],
            ])
            ->assertOk();

        $inspection = VehicleInspection::first();
        $tires = collect($inspection->items)->firstWhere('key', 'tires');
        $this->assertSame('ยางและแรงดันลมยาง', $tires['label']);
    }

    public function test_get_returns_template_and_latest(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();

        // No inspection yet → latest null, template present.
        $data = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/inspection")
            ->assertOk()
            ->json('data');
        $this->assertNull($data['latest']);
        $this->assertCount(count(VehicleInspection::ITEMS), $data['template']);

        // After submitting, latest reflects it.
        $items = collect(VehicleInspection::ITEMS)
            ->map(fn ($i) => ['key' => $i['key'], 'ok' => true])
            ->all();
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/inspection", ['items' => $items])
            ->assertOk();

        $data = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/inspection")
            ->assertOk()
            ->json('data');
        $this->assertNotNull($data['latest']);
        $this->assertTrue($data['latest']['passed']);
    }

    public function test_rejects_unknown_item_key(): void
    {
        [$staff, $schedule] = $this->staffOnSchedule();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/inspection", [
                'items' => [['key' => 'nitro_boost', 'ok' => true]],
            ])
            ->assertStatus(422);
    }

    public function test_forbids_staff_not_on_schedule(): void
    {
        [, $schedule] = $this->staffOnSchedule();
        Role::findOrCreate('staff');
        $outsider = User::factory()->create();
        $outsider->assignRole('staff');

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/inspection")
            ->assertStatus(403);
    }
}
