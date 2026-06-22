<?php

namespace Tests\Feature;

use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PickupTimeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Pickup Time Trip',
            'slug' => 'pickup-time-trip',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(8)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_admin_can_store_pickup_point_with_time(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/pickup-points", [
                'region' => 'central',
                'region_label' => 'ภาคกลาง',
                'pickup_location' => 'BTS หมอชิต',
                'price' => 300,
                'pickup_time' => '05:30',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pickup_time', '05:30');

        $this->assertDatabaseHas('schedule_pickup_points', [
            'schedule_id' => $schedule->id,
            'pickup_time' => '05:30',
        ]);
    }

    public function test_admin_can_update_pickup_point_time(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule();
        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'central',
            'region_label' => 'ภาคกลาง',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 300,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/pickup-points/{$point->id}", [
                'pickup_time' => '06:15',
            ])
            ->assertOk()
            ->assertJsonPath('data.pickup_time', '06:15');

        $this->assertSame('06:15', $point->fresh()->pickup_time);
    }

    public function test_invalid_pickup_time_format_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/pickup-points", [
                'region' => 'central',
                'region_label' => 'ภาคกลาง',
                'pickup_location' => 'จุดทดสอบ',
                'price' => 100,
                'pickup_time' => '5 โมงเช้า',
            ])
            ->assertStatus(422);
    }

    public function test_pickup_time_appears_in_public_schedule_resource(): void
    {
        $schedule = $this->makeSchedule();
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'central',
            'region_label' => 'ภาคกลาง',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 300,
            'pickup_time' => '05:30',
        ]);

        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.pickup_points.0.pickup_time', '05:30');
    }
}
