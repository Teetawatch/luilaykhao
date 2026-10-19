<?php

namespace Tests\Feature;

use App\Jobs\AskTripAttendanceJob;
use App\Jobs\SendBookingRemindersJob;
use App\Jobs\SendTripBriefsJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Jobs\SendUnderfilledTripWarningsJob;
use App\Mail\TripBriefMail;
use App\Mail\TripUnderfilledWarningMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use App\Services\MailService;
use App\Services\SmsService;
use App\Services\TripBriefService;
use App\Services\TripChatTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รอบที่ยังไม่ครบขั้นต่ำ — ไม่ส่งข้อความ "เตรียมตัวเดินทาง" (ใบเดินทาง, SMS/push
 * เตือนก่อนวันเดินทาง, ไทม์ไลน์ในแชท) เพราะลูกค้าเข้าใจว่าทริปไปปกติ แจ้ง "คนยังไม่ครบ"
 * ทั้งช่องทางเดิมและในห้องแชทแทน จนกว่ารอบจะครบหรือทีมงานกด "ยืนยันออกเดินทาง"
 */
class UnderfilledTravelHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-09-10 18:05 เวลาไทย — พรุ่งนี้ (09-11) คือ D-1, 09-17 คือ D-7
        Carbon::setTestNow(Carbon::parse('2026-09-10 11:05:00', 'UTC'));
        config()->set('services.thaibulksms.enabled', false);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(int $booked, string $date = '2026-09-11', array $attributes = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'hold-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 12, 'price_per_person' => 3200, 'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => $date,
            'return_date' => $date,
            'total_seats' => 12, 'booked_seats' => $booked,
            'transport_type' => 'van', 'status' => 'open',
        ], $attributes));
    }

    private function booking(TripSchedule $schedule, int $pax = 1): Booking
    {
        $user = User::factory()->create(['email' => 'cust-'.uniqid().'@example.com', 'phone' => '0812345678']);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3200 * $pax,
            'paid_amount' => 3200 * $pax,
        ]);

        foreach (range(1, $pax) as $i) {
            BookingPassenger::create([
                'booking_id' => $booking->id, 'name' => "ผู้เดินทาง {$i}", 'phone' => '0812345678',
            ]);
        }

        return $booking;
    }

    private function confirm(TripSchedule $schedule): void
    {
        $schedule->forceFill(['departure_confirmed_at' => now()])->save();
    }

    private function runBriefs(): void
    {
        (new SendTripBriefsJob)->handle(app(TripBriefService::class), app(MailService::class), app(SmsService::class));
    }

    private function smsReminderSent(Booking $booking): bool
    {
        return SmsLog::where('booking_id', $booking->id)->where('sms_type', 'departure_reminder')->exists();
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    // ── กติกาที่ตัวรอบ ─────────────────────────────────────────────────

    public function test_only_underfilled_unconfirmed_regular_rounds_are_held(): void
    {
        $this->assertTrue($this->schedule(7)->holdsTravelMessages());
        $this->assertFalse($this->schedule(8)->holdsTravelMessages());
        $this->assertFalse($this->schedule(2, attributes: ['is_charter' => true])->holdsTravelMessages());

        $confirmed = $this->schedule(2);
        $this->confirm($confirmed);
        $this->assertFalse($confirmed->fresh()->holdsTravelMessages());

        $this->assertSame(
            [$confirmed->id],
            TripSchedule::travelMessagesAllowed()->where('booked_seats', '<', 8)->where('is_charter', false)->pluck('id')->all(),
        );
    }

    public function test_confirmed_round_reads_as_guaranteed_to_customers(): void
    {
        $schedule = $this->schedule(3);
        $this->assertSame(TripSchedule::STATUS_WAITING, $schedule->departureStatus());

        $this->confirm($schedule);

        $this->assertSame(TripSchedule::STATUS_GUARANTEED, $schedule->fresh()->departureStatus());
        $this->assertSame(0, $schedule->fresh()->seatsToGuarantee());
    }

    // ── ข้อความเตรียมตัวเดินทางที่ถูกระงับ ─────────────────────────────

    public function test_day_before_sms_and_push_skip_underfilled_rounds(): void
    {
        $held = $this->booking($this->schedule(3));
        $full = $this->booking($this->schedule(8));
        $confirmedSchedule = $this->schedule(3);
        $this->confirm($confirmedSchedule);
        $confirmed = $this->booking($confirmedSchedule);

        (new SendBookingRemindersJob)->handle(app(SmsService::class), app(FcmService::class));
        (new SendTripReminderNotificationsJob)->handle();

        $this->assertFalse($this->smsReminderSent($held));
        $this->assertTrue($this->smsReminderSent($full));
        $this->assertTrue($this->smsReminderSent($confirmed));

        $reminded = SmartNotification::where('type', 'trip_reminder')->pluck('user_id')->all();
        $this->assertNotContains($held->user_id, $reminded);
        $this->assertContains($full->user_id, $reminded);
        $this->assertContains($confirmed->user_id, $reminded);
    }

    public function test_trip_brief_is_held_until_the_round_is_confirmed(): void
    {
        $schedule = $this->schedule(3);
        $booking = $this->booking($schedule);

        $this->runBriefs();

        Mail::assertNotQueued(TripBriefMail::class);
        $this->assertNull($booking->fresh()->brief_sent_at);

        $this->confirm($schedule);
        $this->runBriefs();

        Mail::assertQueued(TripBriefMail::class, 1);
        $this->assertNotNull($booking->fresh()->brief_sent_at);
    }

    public function test_attendance_question_skips_underfilled_rounds(): void
    {
        $held = $this->booking($this->schedule(3), pax: 2);
        $full = $this->booking($this->schedule(8), pax: 2);

        (new AskTripAttendanceJob)->handle();

        $this->assertNull($held->fresh()->attendance_asked_at);
        $this->assertNotNull($full->fresh()->attendance_asked_at);
    }

    public function test_chat_timeline_holds_preparation_posts_while_underfilled(): void
    {
        $held = $this->schedule(3, '2026-09-17');
        $full = $this->schedule(8, '2026-09-17');
        $timeline = app(TripChatTimelineService::class);

        $this->assertNotContains('countdown_7d', $timeline->syncFor($held));
        $this->assertContains('countdown_7d', $timeline->syncFor($full));

        // ยืนยันแล้วลงห้องตามปกติ (ยังอยู่ในช่วงผ่อนผันของข้อความ)
        $this->confirm($held);
        $this->assertContains('countdown_7d', $timeline->syncFor($held->fresh()));
    }

    // ── แจ้งคนไม่ครบในห้องแชท ──────────────────────────────────────────

    public function test_underfilled_warning_is_posted_to_the_chat_room_once(): void
    {
        $schedule = $this->schedule(3, '2026-09-17');
        $this->booking($schedule);

        app()->call([new SendUnderfilledTripWarningsJob, 'handle']);
        app()->call([new SendUnderfilledTripWarningsJob, 'handle']);

        $notice = ChatMessage::where('schedule_id', $schedule->id)->where('system_key', 'underfilled_notice')->get();
        $this->assertCount(1, $notice);
        $this->assertStringContainsString('3/8 ท่าน', $notice[0]->body);
        $this->assertStringContainsString('ขาดอีก 5 ท่าน', $notice[0]->body);
        $this->assertStringContainsString('จะส่งให้เมื่อรอบยืนยันออกเดินทางแล้ว', $notice[0]->body);
    }

    public function test_confirmed_round_gets_no_underfilled_warning(): void
    {
        $schedule = $this->schedule(3, '2026-09-17');
        $this->confirm($schedule);
        $this->booking($schedule);

        app()->call([new SendUnderfilledTripWarningsJob, 'handle']);

        Mail::assertNotQueued(TripUnderfilledWarningMail::class);
        $this->assertFalse(ChatMessage::where('schedule_id', $schedule->id)->where('system_key', 'underfilled_notice')->exists());
    }

    // ── ปุ่ม "ยืนยันออกเดินทาง" ของทีมงาน ──────────────────────────────

    public function test_admin_confirms_departure_and_customers_hear_about_it(): void
    {
        $admin = $this->admin();
        $schedule = $this->schedule(3);
        $booking = $this->booking($schedule);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/confirm-departure")
            ->assertOk()
            ->assertJsonPath('data.notified', 1)
            ->assertJsonPath('data.briefs_queued', 1);

        $schedule->refresh();
        $this->assertNotNull($schedule->departure_confirmed_at);
        $this->assertSame($admin->id, $schedule->departure_confirmed_by);

        $this->assertTrue(SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_departure_confirmed')->exists());
        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)->where('system_key', 'like', 'departure_confirmed:%')->exists());
        // ใบเดินทางที่ค้างไว้ออกทันที ไม่ต้องรอรอบกวาด 18:05
        Mail::assertQueued(TripBriefMail::class, 1);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/confirm-departure")
            ->assertStatus(422);
    }

    public function test_admin_can_revoke_a_mistaken_confirmation(): void
    {
        $admin = $this->admin();
        $schedule = $this->schedule(3);
        $this->confirm($schedule);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/schedules/{$schedule->id}/confirm-departure")
            ->assertOk();

        $this->assertTrue($schedule->fresh()->holdsTravelMessages());
    }

    public function test_customers_cannot_confirm_departure(): void
    {
        $schedule = $this->schedule(3);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/confirm-departure")
            ->assertForbidden();

        $this->assertNull($schedule->fresh()->departure_confirmed_at);
    }

    public function test_at_risk_radar_shows_the_hold_and_the_confirmation(): void
    {
        $admin = $this->admin();
        $held = $this->schedule(3, '2026-09-17');
        $this->booking($held);
        $confirmed = $this->schedule(2, '2026-09-18');
        $this->confirm($confirmed);

        $rows = collect($this->actingAs($admin)->getJson('/api/v1/admin/schedules/at-risk')->assertOk()->json('data.schedules'))
            ->keyBy('id');

        $this->assertTrue($rows[$held->id]['travel_messages_held']);
        $this->assertNull($rows[$held->id]['departure_confirmed_at']);
        $this->assertFalse($rows[$confirmed->id]['travel_messages_held']);
        $this->assertNotNull($rows[$confirmed->id]['departure_confirmed_at']);
    }
}
