<?php

namespace Tests\Feature;

use App\Jobs\SendCheckInRemindersJob;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ~45 นาทีก่อนออกรถ (departs_at, เวลาไทย): เตือนลูกค้าที่ยังไม่เช็คอิน + สรุปให้สตาฟ
 * เวลาถูกตรึงไว้ 20:00 Bangkok เพื่อให้ช่วงเวลาแน่นอน
 */
class CheckInReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-07-05 13:00 UTC = 20:00 Asia/Bangkok.
        Carbon::setTestNow(Carbon::parse('2026-07-05 13:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(?string $departsAt): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Dawn Trek', 'slug' => 'dawn-'.uniqid(), 'type' => 'trekking',
            'location' => 'X', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1800, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-07-05',
            'return_date' => '2026-07-05',
            'total_seats' => 10, 'booked_seats' => 1, 'transport_type' => 'van', 'status' => 'open',
            'departs_at' => $departsAt,
        ]);
    }

    private function booking(TripSchedule $schedule, bool $checkedIn, ?int $userId = null): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $userId ?? User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'checked_in' => $checkedIn,
            'checked_in_at' => $checkedIn ? now() : null,
            'total_amount' => 1800,
        ]);
    }

    public function test_reminds_only_unchecked_passengers_within_the_window(): void
    {
        $schedule = $this->schedule('2026-07-05 20:30:00'); // 30 นาทีข้างหน้า
        $unchecked = $this->booking($schedule, checkedIn: false);
        $checked = $this->booking($schedule, checkedIn: true);

        (new SendCheckInRemindersJob)->handle();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $unchecked->user_id,
            'type' => 'checkin_reminder',
        ]);
        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $checked->user_id,
            'type' => 'checkin_reminder',
        ]);
    }

    public function test_notifies_assigned_staff_with_remaining_count(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $schedule = $this->schedule('2026-07-05 20:30:00');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->booking($schedule, checkedIn: true);
        $this->booking($schedule, checkedIn: false);
        $this->booking($schedule, checkedIn: false);

        (new SendCheckInRemindersJob)->handle();

        $summary = SmartNotification::where('user_id', $staff->id)
            ->where('type', 'checkin_staff_summary')
            ->first();

        $this->assertNotNull($summary);
        $this->assertSame(2, $summary->data['remaining']);
        $this->assertSame(1, $summary->data['checked_in']);
        $this->assertSame(3, $summary->data['total']);
    }

    public function test_staff_summary_reports_all_checked_in(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $schedule = $this->schedule('2026-07-05 20:30:00');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);
        $this->booking($schedule, checkedIn: true);

        (new SendCheckInRemindersJob)->handle();

        $summary = SmartNotification::where('user_id', $staff->id)
            ->where('type', 'checkin_staff_summary')
            ->first();

        $this->assertNotNull($summary);
        $this->assertSame(0, $summary->data['remaining']);
        $this->assertStringContainsString('ครบแล้ว', $summary->title);
    }

    public function test_no_reminder_when_departure_is_far_out(): void
    {
        $schedule = $this->schedule('2026-07-05 23:00:00'); // 3h ahead, นอกหน้าต่าง
        $unchecked = $this->booking($schedule, checkedIn: false);

        (new SendCheckInRemindersJob)->handle();

        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $unchecked->user_id,
            'type' => 'checkin_reminder',
        ]);
    }

    public function test_day_only_round_without_departs_at_is_skipped(): void
    {
        $schedule = $this->schedule(null);
        $unchecked = $this->booking($schedule, checkedIn: false);

        (new SendCheckInRemindersJob)->handle();

        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $unchecked->user_id,
            'type' => 'checkin_reminder',
        ]);
    }

    public function test_reminders_are_sent_only_once(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $schedule = $this->schedule('2026-07-05 20:30:00');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);
        $unchecked = $this->booking($schedule, checkedIn: false);

        (new SendCheckInRemindersJob)->handle();
        (new SendCheckInRemindersJob)->handle();

        $this->assertSame(1, SmartNotification::where('user_id', $unchecked->user_id)
            ->where('type', 'checkin_reminder')->count());
        $this->assertSame(1, SmartNotification::where('user_id', $staff->id)
            ->where('type', 'checkin_staff_summary')->count());
    }
}
