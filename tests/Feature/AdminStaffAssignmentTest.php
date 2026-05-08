<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStaffAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_staff_when_staff_role_uses_a_non_default_guard(): void
    {
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $staffRole = Role::create(['name' => 'staff', 'guard_name' => 'api']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $staff = User::factory()->create();
        DB::table('model_has_roles')->insert([
            'role_id' => $staffRole->id,
            'model_type' => User::class,
            'model_id' => $staff->id,
        ]);

        $trip = Trip::create([
            'title' => 'Staff Test Trip',
            'slug' => 'staff-test-trip',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'return_date' => now()->addWeek()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", [
                'staff_ids' => [$staff->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.staff.0.id', $staff->id);

        $this->assertDatabaseHas('schedule_staff_assignments', [
            'schedule_id' => $schedule->id,
            'user_id' => $staff->id,
            'assigned_by' => $admin->id,
        ]);
    }
}
