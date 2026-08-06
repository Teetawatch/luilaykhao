<?php

namespace Tests\Feature;

use App\Jobs\SendUnderfilledTripWarningsJob;
use App\Mail\TripUnderfilledWarningMail;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
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

    private function booking(int $bookedSeats, string $status = 'open', string $departureDate = '2026-07-12'): Booking
    {
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
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['email' => 'cust-'.uniqid().'@example.com'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1800,
        ]);
    }

    private function warnings(Booking $b)
    {
        return SmartNotification::where('type', 'trip_underfilled_warning')
            ->where('data->booking_ref', $b->booking_ref);
    }

    public function test_warns_when_round_is_underfilled_seven_days_out(): void
    {
        $b = $this->booking(bookedSeats: 3);

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertQueued(TripUnderfilledWarningMail::class, 1);
        $this->assertSame(1, $this->warnings($b)->count());
    }

    public function test_no_warning_when_minimum_is_met(): void
    {
        $this->booking(bookedSeats: 8);

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertNothingQueued();
    }

    public function test_no_warning_for_cancelled_round(): void
    {
        $this->booking(bookedSeats: 3, status: 'cancelled');

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertNothingQueued();
    }

    public function test_no_warning_when_departure_is_not_seven_days_out(): void
    {
        $this->booking(bookedSeats: 3, departureDate: '2026-07-10'); // 5 days out

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertNothingQueued();
    }

    public function test_warning_is_sent_only_once_per_daily_run(): void
    {
        $b = $this->booking(bookedSeats: 3);

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertQueued(TripUnderfilledWarningMail::class, 1);
        $this->assertSame(1, $this->warnings($b)->count());
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

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        $body = $this->warnings($b)->first()->body;

        $this->assertStringContainsString('ขาดอีก 5 ท่าน', $body);
        $this->assertStringContainsString('ชวนเพื่อน', $body);
    }
}
