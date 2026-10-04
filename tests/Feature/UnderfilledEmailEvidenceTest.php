<?php

namespace Tests\Feature;

use App\Jobs\SendUnderfilledTripWarningsJob;
use App\Mail\TripUnderfilledWarningMail;
use App\Models\Booking;
use App\Models\EmailLog;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หลักฐานการแจ้งคนไม่ครบ 7 วันก่อนเดินทาง — ไม่ใช้ Mail::fake() เพราะต้องให้
 * อีเมลผ่าน mailer (array) จริง LogSentEmail ถึงจะได้เติม Message-ID กับเนื้อหา
 */
class UnderfilledEmailEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-07-05 10:00 เวลาไทย → รอบที่ออก 2026-07-12 คือ D-7
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:00:00', 'UTC'));

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(string $email, int $bookedSeats = 3): Booking
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 12, 'price_per_person' => 1800, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-07-12',
            'return_date' => '2026-07-12',
            'total_seats' => 12, 'booked_seats' => $bookedSeats, 'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['email' => $email])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1800,
        ]);
    }

    private function runJob(): void
    {
        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));
    }

    public function test_a_sent_warning_leaves_a_log_with_the_message_id_and_the_html_that_went_out(): void
    {
        $booking = $this->booking('somchai@example.com');

        $this->runJob();

        $log = EmailLog::where('booking_id', $booking->id)->sole();
        $this->assertSame(EmailLog::TYPE_UNDERFILLED_WARNING, $log->type);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame('somchai@example.com', $log->recipient);
        $this->assertSame($booking->booking_ref, $log->booking_ref);
        $this->assertNotEmpty($log->message_id);
        $this->assertNotNull($log->sent_at);
        $this->assertStringContainsString($booking->booking_ref, $log->subject);
        $this->assertStringContainsString('ภูกระดึง', $log->html_body);
        $this->assertSame(3, $log->meta['booked_seats']);
        $this->assertSame('2026-07-12', $log->meta['departure_date']);
    }

    public function test_a_booking_without_a_reachable_email_is_logged_as_skipped(): void
    {
        $booking = $this->booking('manual_0812345678@luilaykhao.com');

        $this->runJob();

        $log = EmailLog::where('booking_id', $booking->id)->sole();
        $this->assertSame(EmailLog::STATUS_SKIPPED, $log->status);
        $this->assertNull($log->recipient);
    }

    public function test_a_mail_that_never_got_out_is_marked_failed(): void
    {
        $booking = $this->booking('somchai@example.com');
        $log = EmailLog::create([
            'type' => EmailLog::TYPE_UNDERFILLED_WARNING,
            'booking_id' => $booking->id,
            'booking_ref' => $booking->booking_ref,
            'recipient' => 'somchai@example.com',
        ]);

        (new TripUnderfilledWarningMail($booking, 7, 3, 8))
            ->logAs($log)
            ->failed(new \RuntimeException('Connection to smtp-relay.brevo.com timed out'));

        $log->refresh();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertNotNull($log->failed_at);
        $this->assertStringContainsString('timed out', $log->error_message);
    }

    public function test_admin_sees_each_round_with_email_and_in_app_evidence(): void
    {
        $booking = $this->booking('somchai@example.com');
        $this->runJob();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/underfilled-emails')
            ->assertOk();

        $res->assertJsonPath('data.summary.schedules', 1)
            ->assertJsonPath('data.summary.emails_sent', 1)
            ->assertJsonPath('data.groups.0.trip_title', 'ภูกระดึง')
            ->assertJsonPath('data.groups.0.booked_seats_at_warning', 3)
            ->assertJsonPath('data.groups.0.bookings.0.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.groups.0.bookings.0.emails.0.status', 'sent')
            ->assertJsonPath('data.groups.0.bookings.0.email_unrecorded', false)
            ->assertJsonPath('data.groups.0.bookings.0.in_app.is_read', false);

        // เนื้อหาไม่ติดมากับรายการ — โหลดเฉพาะตอนเปิดดู
        $this->assertStringNotContainsString('<html', $res->getContent());
    }

    public function test_a_warning_from_before_logging_began_still_shows_its_in_app_evidence(): void
    {
        $booking = $this->booking('somchai@example.com');
        SmartNotification::create([
            'user_id' => $booking->user_id,
            'type' => 'trip_underfilled_warning',
            'title' => 'อัปเดตการยืนยันรอบเดินทาง',
            'body' => '...',
            'data' => ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
            'is_read' => true,
            'read_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/underfilled-emails')
            ->assertOk()
            ->assertJsonPath('data.groups.0.trip_title', 'ภูกระดึง')
            ->assertJsonPath('data.groups.0.bookings.0.email_unrecorded', true)
            ->assertJsonPath('data.groups.0.bookings.0.in_app.is_read', true);
    }

    public function test_admin_can_open_the_email_exactly_as_sent(): void
    {
        $booking = $this->booking('somchai@example.com');
        $this->runJob();
        $log = EmailLog::where('booking_id', $booking->id)->sole();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/underfilled-emails/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.recipient', 'somchai@example.com')
            ->assertJsonPath('data.message_id', $log->message_id)
            ->assertJsonPath('data.html', $log->html_body);
    }

    public function test_customers_cannot_see_the_evidence_page(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/underfilled-emails')
            ->assertForbidden();
    }
}
