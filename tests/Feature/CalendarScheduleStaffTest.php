<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หน้าตารางรอบและที่นั่งว่างต้องเห็นรายชื่อสตาฟประจำรอบ — และต้องเป็นสตาฟที่ยัง
 * รับผิดชอบอยู่เท่านั้น (คนที่ถูกปลดหลังจบทริปไม่ต้องขึ้น)
 */
class CalendarScheduleStaffTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeStaff(string $name, ?string $nickname = null): User
    {
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff = User::factory()->create(['name' => $name, 'nickname' => $nickname, 'phone' => '0812345678']);
        $staff->assignRole('staff');

        return $staff;
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Staff Calendar Trip',
            'slug' => 'staff-calendar-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Nan',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'return_date' => now()->addWeek()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_calendar_payload_lists_the_rounds_staff(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff('สมชาย ใจดี', 'ชาย');
        $schedule->staff()->attach($staff->id);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.staff')
            ->assertJsonPath('data.0.staff.0.id', $staff->id)
            ->assertJsonPath('data.0.staff.0.name', 'สมชาย ใจดี')
            ->assertJsonPath('data.0.staff.0.nickname', 'ชาย')
            ->assertJsonPath('data.0.staff.0.phone', '0812345678');
    }

    public function test_released_staff_are_not_listed(): void
    {
        $schedule = $this->makeSchedule();
        $released = $this->makeStaff('คนเก่า');
        $active = $this->makeStaff('คนปัจจุบัน');
        $schedule->staff()->attach($released->id, ['released_at' => now()]);
        $schedule->staff()->attach($active->id);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.staff')
            ->assertJsonPath('data.0.staff.0.id', $active->id);
    }

    public function test_round_without_staff_returns_an_empty_list(): void
    {
        $this->makeSchedule();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk()
            ->assertJsonCount(0, 'data.0.staff');
    }
}
