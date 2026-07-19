<?php

namespace Tests\Feature;

use App\Jobs\ReleaseEndedTripStaffJob;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReleaseEndedTripStaffTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(string $departure, ?string $return = null, string $status = 'open'): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Release Test Trip '.uniqid(),
            'slug' => 'release-test-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $return,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => $status,
        ]);
    }

    private function makeStaff(): User
    {
        Role::findOrCreate('staff', 'web');
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        return $staff;
    }

    private function makeAdmin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_job_releases_staff_from_a_finished_round_but_keeps_the_record(): void
    {
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule(
            now('Asia/Bangkok')->subDays(4)->toDateString(),
            now('Asia/Bangkok')->subDays(3)->toDateString(),
        );
        $schedule->staff()->attach($staff->id);

        $this->assertSame(1, (new ReleaseEndedTripStaffJob)->handle());

        $schedule->refresh();
        $this->assertCount(0, $schedule->activeStaff);
        // ประวัติต้องอยู่ครบ — สิทธิ์แชท/SOS และตัวนับผลงานอิงแถวนี้
        $this->assertCount(1, $schedule->staff);
        $this->assertNotNull($schedule->releasedStaff->first()->pivot->released_at);
    }

    public function test_job_releases_staff_from_a_cancelled_round(): void
    {
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule(
            now('Asia/Bangkok')->addWeek()->toDateString(),
            now('Asia/Bangkok')->addWeek()->addDay()->toDateString(),
            'cancelled',
        );
        $schedule->staff()->attach($staff->id);

        $this->assertSame(1, (new ReleaseEndedTripStaffJob)->handle());

        $this->assertCount(0, $schedule->refresh()->activeStaff);
    }

    public function test_job_leaves_upcoming_and_todays_rounds_alone(): void
    {
        $staff = $this->makeStaff();
        $upcoming = $this->makeSchedule(
            now('Asia/Bangkok')->addWeek()->toDateString(),
            now('Asia/Bangkok')->addWeek()->addDay()->toDateString(),
        );
        $today = $this->makeSchedule(now('Asia/Bangkok')->toDateString(), now('Asia/Bangkok')->toDateString());
        $upcoming->staff()->attach($staff->id);
        $today->staff()->attach($staff->id);

        $this->assertSame(0, (new ReleaseEndedTripStaffJob)->handle());

        $this->assertCount(1, $upcoming->refresh()->activeStaff);
        $this->assertCount(1, $today->refresh()->activeStaff);
    }

    public function test_admin_can_release_every_staff_member_from_a_round(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule(
            now('Asia/Bangkok')->subDays(3)->toDateString(),
            now('Asia/Bangkok')->subDays(2)->toDateString(),
        );
        $schedule->staff()->attach($staff->id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/staff/release")
            ->assertOk()
            ->assertJsonCount(0, 'data.staff')
            ->assertJsonPath('data.schedule.assigned_staff_count', 0)
            ->assertJsonPath('data.released_staff.0.id', $staff->id);

        $this->assertCount(0, $schedule->refresh()->activeStaff);
    }

    public function test_released_staff_no_longer_count_towards_a_round(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule(
            now('Asia/Bangkok')->subDays(3)->toDateString(),
            now('Asia/Bangkok')->subDays(2)->toDateString(),
        );
        $schedule->staff()->attach($staff->id, ['released_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/schedules?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.0.assigned_staff_count', 0);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/staff/roster?from='.now('Asia/Bangkok')->subWeek()->toDateString().'&to='.now('Asia/Bangkok')->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data.staff');
    }

    public function test_reassigning_a_released_staff_member_revives_the_same_row(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule(
            now('Asia/Bangkok')->addWeek()->toDateString(),
            now('Asia/Bangkok')->addWeek()->addDay()->toDateString(),
        );
        $schedule->staff()->attach($staff->id, ['released_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", ['staff_ids' => [$staff->id]])
            ->assertOk()
            ->assertJsonPath('data.staff.0.id', $staff->id);

        $this->assertDatabaseCount('schedule_staff_assignments', 1);
        $this->assertDatabaseHas('schedule_staff_assignments', [
            'schedule_id' => $schedule->id,
            'user_id' => $staff->id,
            'released_at' => null,
        ]);
    }

    public function test_removing_staff_through_sync_does_not_touch_released_rows(): void
    {
        $admin = $this->makeAdmin();
        $released = $this->makeStaff();
        $active = $this->makeStaff();
        $schedule = $this->makeSchedule(
            now('Asia/Bangkok')->addWeek()->toDateString(),
            now('Asia/Bangkok')->addWeek()->addDay()->toDateString(),
        );
        $schedule->staff()->attach($released->id, ['released_at' => now()]);
        $schedule->staff()->attach($active->id);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", ['staff_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.staff');

        $this->assertDatabaseMissing('schedule_staff_assignments', ['user_id' => $active->id]);
        $this->assertDatabaseHas('schedule_staff_assignments', ['user_id' => $released->id]);
    }

    public function test_released_staff_keep_chat_access_and_their_trip_count(): void
    {
        $staff = $this->makeStaff();
        $schedule = $this->makeSchedule(
            now('Asia/Bangkok')->subDays(4)->toDateString(),
            now('Asia/Bangkok')->subDays(3)->toDateString(),
        );
        $schedule->staff()->attach($staff->id, ['released_at' => now()]);

        $this->assertTrue($schedule->staff()->where('users.id', $staff->id)->exists());
        $this->assertSame(1, $staff->assignedSchedules()->count());
        $this->assertSame(0, $staff->activeAssignedSchedules()->count());
    }
}
