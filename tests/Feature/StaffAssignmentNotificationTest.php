<?php

namespace Tests\Feature;

use App\Jobs\SendStaffShiftRemindersJob;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        return $admin;
    }

    private function makeStaff(): User
    {
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'api']);
        $staff = User::factory()->create();
        DB::table('model_has_roles')->insert([
            'role_id' => $staffRole->id,
            'model_type' => User::class,
            'model_id' => $staff->id,
        ]);

        return $staff;
    }

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Staff Notify Trip',
            'slug' => 'staff-notify-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'return_date' => now()->addWeek()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    public function test_assigning_new_staff_sends_assignment_notification(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", [
                'staff_ids' => [$staff->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $staff->id,
            'type' => 'staff_assignment',
        ]);
    }

    public function test_existing_staff_is_not_renotified_only_newly_added_is(): void
    {
        $admin = $this->makeAdmin();
        $existing = $this->makeStaff();
        $added = $this->makeStaff();
        $schedule = $this->makeSchedule();

        // First assignment: only $existing
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", [
                'staff_ids' => [$existing->id],
            ])
            ->assertOk();

        // Second sync: keep $existing, add $added
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", [
                'staff_ids' => [$existing->id, $added->id],
            ])
            ->assertOk();

        // $existing was notified exactly once (only on the first add)
        $this->assertSame(1, SmartNotification::where('user_id', $existing->id)
            ->where('type', 'staff_assignment')->count());

        // $added was notified once (the second sync)
        $this->assertSame(1, SmartNotification::where('user_id', $added->id)
            ->where('type', 'staff_assignment')->count());
    }

    public function test_shift_reminder_sent_to_assigned_staff_for_tomorrow_and_deduped(): void
    {
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule([
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
        ]);
        $schedule->staff()->attach($staff->id);

        (new SendStaffShiftRemindersJob)->handle();

        $this->assertSame(1, SmartNotification::where('user_id', $staff->id)
            ->where('type', 'staff_shift_reminder')->count());

        // Running again must not double-send.
        (new SendStaffShiftRemindersJob)->handle();

        $this->assertSame(1, SmartNotification::where('user_id', $staff->id)
            ->where('type', 'staff_shift_reminder')->count());
    }

    public function test_shift_reminder_skips_cancelled_schedule(): void
    {
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule([
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'status' => 'cancelled',
        ]);
        $schedule->staff()->attach($staff->id);

        (new SendStaffShiftRemindersJob)->handle();

        $this->assertSame(0, SmartNotification::where('user_id', $staff->id)
            ->where('type', 'staff_shift_reminder')->count());
    }
}
