<?php

namespace Tests\Feature;

use App\Mail\BalanceDueReminderMail;
use App\Mail\PaymentConfirmedMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * บัญชีที่ทีมงานเปิดใบจองแทนลูกค้าโดยไม่ได้กรอกอีเมล จะได้ที่อยู่ปลอม
 * manual_...@luilaykhao.com ติดตัวไว้ (AdminController::storeBooking)
 *
 * ทุกฉบับที่ยิงไปที่นั่นคือ hard bounce ที่กดคะแนนโดเมนผู้ส่งของเรา และพาอีเมล
 * ที่ส่งถึงลูกค้าจริงเข้าถังขยะไปด้วย — ต้องไม่หลุดออกไปจากเส้นทางไหนเลย
 */
class PlaceholderEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function booking(string $email, ?string $passengerEmail = null): Booking
    {
        $trip = Trip::create([
            'title' => 'เขาสามร้อยยอด', 'slug' => 'khao-'.uniqid(), 'type' => 'trekking',
            'location' => 'ประจวบฯ', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1500, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id, 'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(10)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['email' => $email])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 750,
            'balance_amount' => 750,
            'balance_due_at' => now()->addDays(3),
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'ผู้เดินทาง', 'email' => $passengerEmail,
        ]);

        return $booking->fresh();
    }

    public function test_placeholder_address_never_receives_mail(): void
    {
        $booking = $this->booking('manual_1757600000_ab12@luilaykhao.com');

        app(MailService::class)->sendBalanceDueReminderEmail($booking);
        app(MailService::class)->sendPaymentConfirmedEmail($booking, 'full');

        Mail::assertNothingQueued();
    }

    public function test_a_passenger_email_still_receives_mail_on_a_shadow_booking(): void
    {
        // แอดมินไม่ได้กรอกอีเมลตอนเปิดบัญชี แต่กรอกไว้ที่ผู้เดินทาง — ฉบับนี้ส่งได้
        $booking = $this->booking('manual_1757600000_cd34@luilaykhao.com', 'real@example.com');

        app(MailService::class)->sendBalanceDueReminderEmail($booking);

        Mail::assertQueued(BalanceDueReminderMail::class, fn ($mail) => $mail->hasTo('real@example.com'));
    }

    public function test_normal_account_is_unaffected(): void
    {
        $booking = $this->booking('customer@example.com');

        app(MailService::class)->sendPaymentConfirmedEmail($booking, 'full');

        Mail::assertQueued(PaymentConfirmedMail::class, fn ($mail) => $mail->hasTo('customer@example.com'));
    }

    public function test_a_real_luilaykhao_address_is_not_mistaken_for_a_placeholder(): void
    {
        // ทีมงานที่ใช้อีเมลโดเมนบริษัทจองทริปเองต้องยังได้รับอีเมลตามปกติ
        $booking = $this->booking('staff@luilaykhao.com');

        app(MailService::class)->sendPaymentConfirmedEmail($booking, 'full');

        Mail::assertQueued(PaymentConfirmedMail::class, fn ($mail) => $mail->hasTo('staff@luilaykhao.com'));
    }
}
