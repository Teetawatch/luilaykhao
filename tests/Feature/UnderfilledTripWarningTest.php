<?php

namespace Tests\Feature;

use App\Jobs\SendUnderfilledTripWarningsJob;
use App\Mail\TripUnderfilledWarningMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\EmailLog;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 7 days before departure, warn customers whose round hasn't reached the 8-seat
 * minimum that the trip may be cancelled. Time is frozen so the target date is
 * deterministic.
 */
class UnderfilledTripWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-07-05 03:00 UTC = 10:00 Asia/Bangkok → target date = 2026-07-12.
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:00:00', 'UTC'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(
        int $bookedSeats,
        string $status = 'open',
        string $departureDate = '2026-07-12',
        array $schedule = [],
        array $booking = [],
    ): Booking {
        $trip = Trip::create([
            'title' => 'Dawn Trek', 'slug' => 'dawn-'.uniqid(), 'type' => 'trekking',
            'location' => 'X', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 12, 'price_per_person' => 1800, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => $departureDate,
            'total_seats' => 12, 'booked_seats' => $bookedSeats, 'transport_type' => 'van', 'status' => $status,
        ] + $schedule);

        return $this->bookingOn($schedule, $booking);
    }

    private function bookingOn(TripSchedule $schedule, array $overrides = []): Booking
    {
        return Booking::create($overrides + [
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create([
                'email' => 'cust-'.uniqid().'@example.com',
                'phone' => '0812345678',
            ])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1800,
        ]);
    }

    private function runJob(): void
    {
        app()->call([new SendUnderfilledTripWarningsJob, 'handle']);
    }

    private function sms(Booking $b)
    {
        return SmsLog::where('booking_id', $b->id)->where('sms_type', SmsService::UNDERFILLED_WARNING);
    }

    private function warnings(Booking $b)
    {
        return SmartNotification::where('type', 'trip_underfilled_warning')
            ->where('data->booking_ref', $b->booking_ref);
    }

    public function test_warns_when_round_is_underfilled_seven_days_out(): void
    {
        $b = $this->booking(bookedSeats: 3);

        $this->runJob();

        Mail::assertQueued(TripUnderfilledWarningMail::class, 1);
        $this->assertSame(1, $this->warnings($b)->count());
    }

    public function test_no_warning_when_minimum_is_met(): void
    {
        $this->booking(bookedSeats: 8);

        $this->runJob();

        Mail::assertNothingQueued();
    }

    public function test_no_warning_for_cancelled_round(): void
    {
        $this->booking(bookedSeats: 3, status: 'cancelled');

        $this->runJob();

        Mail::assertNothingQueued();
    }

    public function test_no_warning_outside_the_seven_to_two_day_window(): void
    {
        $this->booking(bookedSeats: 3, departureDate: '2026-07-13'); // D-8 ยังไม่ถึงเวลา
        $this->booking(bookedSeats: 3, departureDate: '2026-07-06'); // D-1 สายเกินไปแล้ว

        $this->runJob();

        Mail::assertNotQueued(TripUnderfilledWarningMail::class);
        $this->assertSame(0, SmsLog::where('sms_type', SmsService::UNDERFILLED_WARNING)->count());
    }

    /**
     * วันที่ D-7 worker ล่ม / job ล้ม ต้องไม่ทำให้รอบนั้นไม่มีใครได้แจ้งเลย —
     * รอบถัดไปของ job ตามเก็บได้จนถึง D-2 และบอกจำนวนวันตามจริง
     */
    public function test_a_round_missed_on_day_seven_is_caught_up_later(): void
    {
        $b = $this->booking(bookedSeats: 3, departureDate: '2026-07-10'); // D-5

        $this->runJob();

        Mail::assertQueued(TripUnderfilledWarningMail::class, fn ($mail) => $mail->daysBefore === 5);
        $this->assertSame(1, $this->warnings($b)->count());
        $this->assertSame(5, EmailLog::where('booking_id', $b->id)->sole()->meta['days_before']);
    }

    public function test_warning_is_sent_only_once_however_often_the_job_runs(): void
    {
        $b = $this->booking(bookedSeats: 3);

        $this->runJob();
        $this->runJob();
        Carbon::setTestNow(Carbon::parse('2026-07-06 03:00:00', 'UTC')); // วันรุ่งขึ้น D-6
        $this->runJob();

        Mail::assertQueued(TripUnderfilledWarningMail::class, 1);
        $this->assertSame(1, $this->warnings($b)->count());
        $this->assertSame(1, $this->sms($b)->count());
        $this->assertSame(1, EmailLog::where('booking_id', $b->id)->count());
    }

    /** จองเข้ามาหลังวันที่แจ้งไปแล้ว ก็ต้องได้รู้กติกาเดียวกัน — คนเดิมไม่โดนซ้ำ */
    public function test_a_booking_made_after_the_first_warning_is_warned_too(): void
    {
        $first = $this->booking(bookedSeats: 3);
        $this->runJob();

        $late = $this->bookingOn($first->schedule);
        $this->runJob();

        Mail::assertQueued(TripUnderfilledWarningMail::class, 2);
        $this->assertSame(1, $this->warnings($first)->count());
        $this->assertSame(1, $this->warnings($late)->count());
    }

    public function test_sms_goes_out_with_the_round_and_what_to_do(): void
    {
        $b = $this->booking(bookedSeats: 3);

        $this->runJob();

        $sms = $this->sms($b)->sole();
        $this->assertSame('66812345678', $sms->recipient);
        $this->assertSame('pending', $sms->status);
        $this->assertStringContainsString('Dawn Trek', $sms->message);
        $this->assertStringContainsString('3/8 ท่าน', $sms->message);
        $this->assertStringContainsString('คืนเงินเต็มจำนวน', $sms->message);
        $this->assertStringContainsString(config('app.support_line_id'), $sms->message);
        $this->assertStringContainsString($b->booking_ref, $sms->message);
        $this->assertStringContainsString('ส่งทางอีเมลแล้ว', $sms->message);
    }

    /** ไม่มีอีเมล SMS ห้ามอ้างว่าส่งอีเมลไปแล้ว */
    public function test_sms_does_not_mention_an_email_that_was_never_sent(): void
    {
        $b = $this->booking(bookedSeats: 3);
        $b->user->update(['email' => 'manual_1_ab@luilaykhao.com']);

        $this->runJob();

        $this->assertStringNotContainsString('อีเมล', $this->sms($b)->sole()->message);
    }

    /** ผู้เดินทางคนแรกไม่ได้กรอกเบอร์ ใช้เบอร์ของบัญชีที่จองแทน ไม่ใช่ข้ามไปเลย */
    public function test_sms_falls_back_to_the_bookers_phone(): void
    {
        $b = $this->booking(bookedSeats: 3);
        BookingPassenger::create(['booking_id' => $b->id, 'name' => 'สมชาย ใจดี', 'phone' => null]);

        $this->runJob();

        $this->assertSame('66812345678', $this->sms($b)->sole()->recipient);
    }

    /** อีเมลในช่องผู้เดินทางพิมพ์ผิดได้ — ผู้จองที่จ่ายเงินต้องได้ฉบับของตัวเองด้วย */
    public function test_email_reaches_both_the_passenger_and_the_booker(): void
    {
        $b = $this->booking(bookedSeats: 3);
        BookingPassenger::create(['booking_id' => $b->id, 'name' => 'สมชาย ใจดี', 'email' => 'friend@example.com']);

        $this->runJob();

        $this->assertEqualsCanonicalizing(
            ['friend@example.com', $b->user->email],
            EmailLog::where('booking_id', $b->id)->pluck('recipient')->all(),
        );
    }

    public function test_a_failed_email_is_retried_until_the_attempt_limit(): void
    {
        $b = $this->booking(bookedSeats: 3);
        $failed = fn () => EmailLog::create([
            'type' => EmailLog::TYPE_UNDERFILLED_WARNING,
            'booking_id' => $b->id,
            'schedule_id' => $b->schedule_id,
            'booking_ref' => $b->booking_ref,
            'recipient' => $b->user->email,
            'status' => EmailLog::STATUS_FAILED,
        ]);

        $failed();
        $this->runJob();
        Mail::assertQueued(TripUnderfilledWarningMail::class, 1);

        EmailLog::where('booking_id', $b->id)->update(['status' => EmailLog::STATUS_FAILED]);
        $failed();
        $this->runJob();

        // ล้มครบ 3 ฉบับแล้ว เลิกยิงซ้ำ — หน้าหลักฐานโชว์ให้ทีมงานตามเอง
        Mail::assertQueued(TripUnderfilledWarningMail::class, 1);
        $this->assertSame(MailService::UNDERFILLED_MAX_ATTEMPTS, EmailLog::where('booking_id', $b->id)->count());
    }

    public function test_charter_round_is_never_warned(): void
    {
        $this->booking(bookedSeats: 3, schedule: ['is_charter' => true]);

        $this->runJob();

        Mail::assertNothingQueued();
    }

    /** ใบที่จ่ายเงินแล้วแต่ยังรอตรวจสลิปมีเงินค้างอยู่กับเรา ต้องรู้ด้วย — ใบที่ยังไม่จ่ายไม่ต้อง */
    public function test_paid_pending_bookings_are_warned_but_unpaid_ones_are_not(): void
    {
        $paid = $this->booking(bookedSeats: 3, booking: ['status' => 'pending', 'paid_amount' => 900]);
        $unpaid = $this->bookingOn($paid->schedule, ['status' => 'pending', 'paid_amount' => 0]);

        $this->runJob();

        $this->assertSame(1, $this->warnings($paid)->count());
        $this->assertSame(0, $this->warnings($unpaid)->count());
        $this->assertSame(0, $this->sms($unpaid)->count());
    }

    public function test_email_renders_with_the_seat_details(): void
    {
        $b = $this->booking(bookedSeats: 3);

        $html = (new TripUnderfilledWarningMail($b, 7, 3, 8))->render();

        $this->assertStringContainsString('ข้อมูลการยืนยันรอบเดินทาง', $html);
        $this->assertStringContainsString('Dawn Trek', $html);
        $this->assertStringContainsString('3 / 8 ท่าน', $html);
        $this->assertStringContainsString('อีกเพียง 5 ท่าน', $html); // 8 minimum − 3 booked
        $this->assertStringContainsString('อย่างน้อย 7 วันก่อนวันเดินทาง', $html);
    }

    /**
     * เมลนี้เคยบอกแค่ว่าคนยังไม่ครบแล้วจบ ตอนนี้ต้องมี "ทางออก" ให้กดจริง —
     * ลิงก์ชวนเพื่อนของผู้จองคนนั้น และรอบอื่นที่ยังจองได้
     */
    public function test_email_offers_an_invite_link_and_other_rounds(): void
    {
        $b = $this->booking(bookedSeats: 3);

        // รอบอื่นของทริปเดียวกันที่ยังเปิดขายและมีที่นั่งว่าง
        TripSchedule::create([
            'trip_id' => $b->schedule->trip_id,
            'departure_date' => '2026-08-15',
            'return_date' => '2026-08-15',
            'total_seats' => 12, 'booked_seats' => 9,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        $html = (new TripUnderfilledWarningMail($b, 7, 3, 8))->render();

        $this->assertStringContainsString('ส่งลิงก์ชวนเพื่อนมาร่วมทริป', $html);
        $this->assertStringContainsString('?schedule='.$b->schedule_id, $html);
        $this->assertStringContainsString('รอบอื่นของทริปนี้ที่ยังจองได้', $html);
        $this->assertStringContainsString('9 ท่านแล้ว', $html);
    }

    /**
     * ลูกค้าต้องรู้ว่าไม่ต้องรอให้เรายกเลิกก่อน — ระหว่างนี้ขอยกเลิกเองแล้วรับเงินคืน
     * เต็มจำนวนได้ทันที และต้องมีช่องทางแจ้ง (LINE / โทร) อยู่ในย่อหน้าเดียวกัน
     *
     * และต้องกำกับว่าเป็นข้อยกเว้นเฉพาะรอบที่คนไม่ครบ ไม่ใช่นโยบายยกเลิกปกติ
     * (นโยบายจริงใน config/payment.php คือน้อยกว่า 30 วันไม่คืนเงิน)
     */
    public function test_email_says_a_full_refund_is_available_on_request_right_now(): void
    {
        $b = $this->booking(bookedSeats: 3);

        $html = (new TripUnderfilledWarningMail($b, 7, 3, 8))->render();

        $this->assertStringContainsString('ระหว่าง 7 วันนี้', $html);
        $this->assertStringContainsString('รับเงินคืนเต็มจำนวนได้ทันที', $html);
        $this->assertStringContainsString('เฉพาะรอบที่ผู้ร่วมทริปยังไม่ครบตามกำหนดนี้', $html);
        $this->assertStringContainsString(config('app.support_line_id'), $html);
        $this->assertStringContainsString(config('company.phone'), $html);
    }

    public function test_push_tells_the_customer_what_they_can_do(): void
    {
        $b = $this->booking(bookedSeats: 3);

        $this->runJob();

        $body = $this->warnings($b)->first()->body;

        $this->assertStringContainsString('ขาดอีก 5 ท่าน', $body);
        $this->assertStringContainsString('ชวนเพื่อน', $body);
    }
}
