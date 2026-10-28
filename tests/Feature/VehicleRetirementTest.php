<?php

namespace Tests\Feature;

use App\Models\ScheduleVehicleOption;
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

    private function makeSchedule(?Vehicle $vehicle, string $date, string $status = 'open', ?string $returnDate = null): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $this->trip->id, 'vehicle_id' => $vehicle?->id,
            'departure_date' => $date, 'return_date' => $returnDate ?? $date,
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

    private function idsFor(string $status): array
    {
        return collect(
            $this->actingAs($this->admin, 'sanctum')
                ->getJson("/api/v1/admin/vehicles?status={$status}&per_page=100")
                ->assertOk()->json('data')
        )->pluck('id')->all();
    }

    public function test_usage_status_separates_active_never_used_and_past(): void
    {
        $active = $this->makeVehicle('รถมีรอบ');
        $this->makeSchedule($active, now('Asia/Bangkok')->addDays(3)->toDateString());
        $unused = $this->makeVehicle('รถยังไม่เคยใช้');
        $past = $this->makeVehicle('รถใช้ผ่านมาแล้ว');
        $this->makeSchedule($past, now('Asia/Bangkok')->subDays(3)->toDateString());

        $this->assertSame('active', $this->vehiclePayload($active->id)['usage_status']);
        $this->assertSame('never_used', $this->vehiclePayload($unused->id)['usage_status']);
        $this->assertSame('past', $this->vehiclePayload($past->id)['usage_status']);
        $this->assertNotNull($this->vehiclePayload($unused->id)['created_at']);

        $this->assertSame([$unused->id], $this->idsFor('never_used'));
        $this->assertSame([$past->id], $this->idsFor('past'));
    }

    public function test_multi_day_round_still_on_the_road_keeps_the_vehicle_in_use(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeSchedule(
            $vehicle,
            now('Asia/Bangkok')->subDays(1)->toDateString(),
            returnDate: now('Asia/Bangkok')->addDay()->toDateString(),
        );

        $this->assertSame('active', $this->vehiclePayload($vehicle->id)['usage_status']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
            ->assertStatus(422);
    }

    public function test_vehicle_used_only_through_a_vehicle_option_counts_as_in_use(): void
    {
        $bus = $this->makeVehicle('รถบัสตัวเลือก');
        $schedule = $this->makeSchedule(null, now('Asia/Bangkok')->addDays(5)->toDateString());
        ScheduleVehicleOption::create(['schedule_id' => $schedule->id, 'label' => 'รถบัส', 'vehicle_id' => $bus->id]);

        $payload = $this->vehiclePayload($bus->id);
        $this->assertSame('active', $payload['usage_status']);
        $this->assertSame(1, $payload['upcoming_schedules_count']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/vehicles/{$bus->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('vehicles', ['id' => $bus->id]);
    }

    public function test_vehicle_option_on_a_past_round_counts_as_past_use(): void
    {
        $bus = $this->makeVehicle('รถบัสเคยใช้');
        $date = now('Asia/Bangkok')->subDays(12)->toDateString();
        $schedule = $this->makeSchedule(null, $date);
        ScheduleVehicleOption::create(['schedule_id' => $schedule->id, 'label' => 'รถบัส', 'vehicle_id' => $bus->id]);

        $payload = $this->vehiclePayload($bus->id);
        $this->assertSame('past', $payload['usage_status']);
        $this->assertSame(1, $payload['schedules_count']);
        $this->assertStringStartsWith($date, $payload['last_departure_date']);
    }

    public function test_bulk_delete_clears_unused_and_past_vehicles_but_skips_ones_in_use(): void
    {
        $unused = $this->makeVehicle('รถยังไม่เคยใช้');
        $past = $this->makeVehicle('รถใช้ผ่านมาแล้ว');
        $pastRound = $this->makeSchedule($past, now('Asia/Bangkok')->subDays(20)->toDateString());
        $active = $this->makeVehicle('รถมีรอบ');
        $this->makeSchedule($active, now('Asia/Bangkok')->addDays(2)->toDateString());

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/vehicles/bulk-delete', ['ids' => [$unused->id, $past->id, $active->id]])
            ->assertOk();

        $this->assertSame(2, $res->json('data.deleted'));
        $this->assertSame([$active->id], collect($res->json('data.skipped'))->pluck('id')->all());
        $this->assertDatabaseMissing('vehicles', ['id' => $unused->id]);
        $this->assertDatabaseMissing('vehicles', ['id' => $past->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $active->id]);
        // รอบเก่ายังอยู่ แต่ไม่ผูกกับรถแล้ว
        $this->assertNull($pastRound->fresh()->vehicle_id);
    }

    public function test_bulk_delete_requires_admin_area_access(): void
    {
        $vehicle = $this->makeVehicle();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/admin/vehicles/bulk-delete', ['ids' => [$vehicle->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_clear_all_never_used_vehicles_leaves_past_and_active_ones(): void
    {
        $unusedA = $this->makeVehicle('รถยังไม่เคยใช้ A');
        $unusedB = $this->makeVehicle('รถยังไม่เคยใช้ B');
        $past = $this->makeVehicle('รถใช้ผ่านมาแล้ว');
        $this->makeSchedule($past, now('Asia/Bangkok')->subDays(4)->toDateString());
        $active = $this->makeVehicle('รถมีรอบ');
        $this->makeSchedule($active, now('Asia/Bangkok')->addDays(4)->toDateString());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/vehicles/bulk-delete', ['status' => 'never_used'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertDatabaseMissing('vehicles', ['id' => $unusedA->id]);
        $this->assertDatabaseMissing('vehicles', ['id' => $unusedB->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $past->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $active->id]);
    }

    public function test_clear_all_past_vehicles_respects_the_type_filter(): void
    {
        $pastVan = $this->makeVehicle('ตู้เก่า');
        $this->makeSchedule($pastVan, now('Asia/Bangkok')->subDays(4)->toDateString());
        $pastBus = Vehicle::create(['name' => 'บัสเก่า', 'type' => 'bus', 'capacity' => 40]);
        $this->makeSchedule($pastBus, now('Asia/Bangkok')->subDays(4)->toDateString());
        $unused = $this->makeVehicle('ตู้ใหม่');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/vehicles/bulk-delete', ['status' => 'past', 'type' => 'van'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('vehicles', ['id' => $pastVan->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $pastBus->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $unused->id]);
    }

    public function test_clear_all_inactive_never_touches_vehicles_in_use(): void
    {
        $unused = $this->makeVehicle('รถยังไม่เคยใช้');
        $active = $this->makeVehicle('รถมีรอบ');
        $this->makeSchedule($active, now('Asia/Bangkok')->addDays(1)->toDateString());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/vehicles/bulk-delete', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('vehicles', ['id' => $unused->id]);
        $this->assertDatabaseHas('vehicles', ['id' => $active->id]);

        // ไม่มีทางสั่งเคลียร์กองที่ยังใช้งานอยู่
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/vehicles/bulk-delete', ['status' => 'active'])
            ->assertStatus(422);
        $this->assertDatabaseHas('vehicles', ['id' => $active->id]);
    }
}
