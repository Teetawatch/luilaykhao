<?php

namespace Tests\Feature;

use App\Jobs\SendInstallmentRemindersJob;
use App\Mail\InstallmentDueReminderMail;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\SmsService;
use App\Services\ThaiBulkSmsClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * งานเตือนที่ไม่ได้วิ่งมานาน (คิวค้าง worker ล่ม) ต้องกลับมาทำงานต่อโดยไม่ยิง
 * ข้อความเก่าที่หมดความหมายแล้วใส่ลูกค้าทีเดียวทั้งกอง
 */
class StaleReminderGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 01:00:00', 'UTC'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(string $status = 'confirmed'): Booking
    {
        $trip = Trip::create([
            'title' => 'Doi Trek', 'slug' => 'doi-'.uniqid(), 'type' => 'trekking',
            'location' => 'X', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 12, 'price_per_person' => 3000, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id, 'departure_date' => '2026-12-20', 'return_date' => '2026-12-20',
            'total_seats' => 12, 'booked_seats' => 4, 'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['phone' => '0812345678'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'payment_type' => 'installment',
            'total_amount' => 3000,
        ]);
    }

    private function installment(Booking $booking, string $dueDate): InstallmentPayment
    {
        return InstallmentPayment::create([
            'booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 1000,
            'due_date' => $dueDate, 'status' => 'pending',
        ]);
    }

    private function runInstallmentJob(): void
    {
        app()->call([new SendInstallmentRemindersJob, 'handle']);
    }

    public function test_a_freshly_overdue_installment_is_still_chased(): void
    {
        $ip = $this->installment($this->booking(), '2026-10-09');

        $this->runInstallmentJob();

        $this->assertSame('overdue', $ip->fresh()->status);
        Mail::assertQueued(InstallmentDueReminderMail::class, 1);
        $this->assertSame(1, SmsLog::where('sms_type', 'installment_overdue')->count());
    }

    /** เลยมาเป็นเดือนแล้วเพิ่งเจอ — ติดสถานะให้ถูก แต่ไม่ส่ง "เลยกำหนดมานิดนึง" */
    public function test_a_long_overdue_installment_is_flagged_without_messaging_the_customer(): void
    {
        $ip = $this->installment($this->booking(), '2026-08-01');

        $this->runInstallmentJob();

        $this->assertSame('overdue', $ip->fresh()->status);
        Mail::assertNotQueued(InstallmentDueReminderMail::class);
        $this->assertSame(0, SmsLog::where('sms_type', 'installment_overdue')->count());
    }

    public function test_a_cancelled_booking_is_never_told_it_owes_an_installment(): void
    {
        $ip = $this->installment($this->booking('cancelled'), '2026-10-09');

        $this->runInstallmentJob();

        $this->assertSame('pending', $ip->fresh()->status);
        Mail::assertNotQueued(InstallmentDueReminderMail::class);
    }

    public function test_pending_sms_older_than_the_retry_window_is_not_sent(): void
    {
        config()->set('services.thaibulksms', [
            'enabled' => true, 'api_key' => 'k', 'api_secret' => 's', 'sender' => 'LLK',
        ] + config('services.thaibulksms', []));

        $booking = $this->booking();
        $log = fn (string $key, Carbon $at) => tap(SmsLog::create([
            'booking_id' => $booking->id, 'provider' => 'thaibulksms', 'sms_type' => 'payment_confirmed',
            'dedupe_key' => $key, 'recipient' => '66812345678', 'message' => 'x',
            'status' => 'pending', 'scheduled_at' => $at,
        ]), function ($row) use ($at) {
            $row->forceFill(['created_at' => $at])->save();
        });

        $fresh = $log('fresh', now()->subHour());
        $stale = $log('stale', now()->subDays(SmsService::RETRY_WITHIN_HOURS / 24 + 1));

        $this->mock(ThaiBulkSmsClient::class)
            ->shouldReceive('send')->once()->with('66812345678', 'x')
            ->andReturn(['ok' => true, 'status' => 200, 'payload' => [], 'body' => ['id' => 'abc']]);

        $this->assertSame(1, app(SmsService::class)->sendPending());
        $this->assertSame('sent', $fresh->fresh()->status);
        $this->assertSame('pending', $stale->fresh()->status);
    }
}
