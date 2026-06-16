<?php

namespace Tests\Feature;

use App\Jobs\SendAnnouncementPushJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ScheduleAnnouncement;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Announce Trip',
            'slug' => 'announce-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function bookOnto(User $user, TripSchedule $schedule): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'Passenger',
            'phone' => '0812345678',
        ]);

        return $booking;
    }

    private function makeStaff(TripSchedule $schedule): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    public function test_staff_can_post_announcement_and_fans_out_push(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/announcements", [
                'category' => 'meeting_point',
                'title' => 'เปลี่ยนจุดนัดพบ',
                'body' => 'ย้ายไปปั๊ม ปตท. พระราม 2 เวลา 6 โมงเช้า',
                'is_pinned' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'meeting_point')
            ->assertJsonPath('data.is_pinned', true)
            ->assertJsonPath('data.author_name', $staff->nickname ?: $staff->name);

        $this->assertDatabaseHas('schedule_announcements', [
            'schedule_id' => $schedule->id,
            'title' => 'เปลี่ยนจุดนัดพบ',
            'category' => 'meeting_point',
            'is_pinned' => true,
        ]);

        Bus::assertDispatched(SendAnnouncementPushJob::class);
    }

    public function test_customer_can_list_announcements_pinned_first(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        // โพสต์ปกติก่อน (ใหม่กว่า) แล้วโพสต์ปักหมุดทีหลัง → ปักหมุดต้องมาก่อน
        ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id, 'author_id' => $staff->id,
            'category' => 'general', 'title' => 'ทั่วไป', 'body' => 'ข้อความทั่วไป', 'is_pinned' => false,
        ]);
        ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id, 'author_id' => $staff->id,
            'category' => 'urgent', 'title' => 'ด่วน', 'body' => 'เรื่องด่วน', 'is_pinned' => true,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/announcements")
            ->assertOk()
            ->assertJsonPath('data.announcements.0.title', 'ด่วน')
            ->assertJsonPath('data.announcements.0.is_pinned', true)
            ->assertJsonPath('data.announcements.1.title', 'ทั่วไป')
            ->assertJsonPath('data.can_moderate', false)
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_non_member_cannot_view_or_post(): void
    {
        $schedule = $this->makeSchedule();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/announcements")
            ->assertForbidden();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/announcements", [
                'title' => 'x', 'body' => 'y',
            ])
            ->assertForbidden();
    }

    public function test_customer_cannot_post_announcement(): void
    {
        $schedule = $this->makeSchedule();
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/announcements", [
                'title' => 'ขอประกาศเอง', 'body' => 'ไม่น่าได้',
            ])
            ->assertForbidden();
    }

    public function test_post_requires_title_and_body(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/announcements", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'body']);
    }

    public function test_unread_count_and_mark_read(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id, 'author_id' => $staff->id,
            'category' => 'general', 'title' => 'หนึ่ง', 'body' => 'a',
        ]);
        ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id, 'author_id' => $staff->id,
            'category' => 'general', 'title' => 'สอง', 'body' => 'b',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/announcements/unread-count")
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/announcements/read")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/announcements/unread-count")
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_author_is_marked_read_on_post(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/announcements", [
                'title' => 'แจ้งทีม', 'body' => 'รายละเอียด',
            ])
            ->assertCreated();

        // ผู้โพสต์ไม่ควรมีประกาศค้างเป็น unread
        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/announcements/unread-count")
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_staff_can_pin_unpin_and_delete(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);
        $announcement = ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id, 'author_id' => $staff->id,
            'category' => 'general', 'title' => 'หมุด', 'body' => 'x', 'is_pinned' => false,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/announcements/{$announcement->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);
        $this->assertTrue($announcement->fresh()->is_pinned);

        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/announcements/{$announcement->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', false);
        $this->assertFalse($announcement->fresh()->is_pinned);

        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/announcements/{$announcement->id}")
            ->assertOk();
        $this->assertDatabaseMissing('schedule_announcements', ['id' => $announcement->id]);
    }
}
