<?php

namespace Tests\Feature;

use App\Mail\AdminNewBookingMail;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\BalanceDueReminderMail;
use App\Mail\BalancePaidMail;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingCreatedMail;
use App\Mail\BookingStatusChangedMail;
use App\Mail\DepositPaidMail;
use App\Mail\EmailVerificationMail;
use App\Mail\GiftClaimedMail;
use App\Mail\GiftPurchasedMail;
use App\Mail\InstallmentDueReminderMail;
use App\Mail\InstallmentPaidMail;
use App\Mail\PasswordResetMail;
use App\Mail\PaymentConfirmedMail;
use App\Mail\TripUnderfilledWarningMail;
use App\Mail\WelcomeRegistrationMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mailables are queued, so nothing renders their Blade during a normal test run —
 * a broken template ships silently. Render every one of them here instead.
 */
class EmailTemplatesRenderTest extends TestCase
{
    use RefreshDatabase;

    private Booking $booking;

    private InstallmentPayment $installment;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::create([
            'title' => 'Dawn Trek', 'slug' => 'dawn-'.uniqid(), 'type' => 'trekking',
            'location' => 'X', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 12, 'price_per_person' => 1800, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-08-10',
            'return_date' => '2026-08-10',
            'total_seats' => 12, 'booked_seats' => 3, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $this->booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['name' => 'สมชาย ใจดี'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'payment_type' => 'installment',
            'installment_count' => 3,
            'total_amount' => 5400,
            'paid_amount' => 1800,
            'deposit_amount' => 1800,
            'balance_amount' => 3600,
            'balance_due_at' => '2026-08-01',
        ]);
        BookingPassenger::create([
            'booking_id' => $this->booking->id, 'name' => 'สมชาย ใจดี', 'phone' => '0812345678',
        ]);
        $this->installment = InstallmentPayment::create([
            'booking_id' => $this->booking->id,
            'installment_no' => 1,
            'amount' => 1800,
            'due_date' => '2026-08-01',
            'status' => 'paid',
        ]);
        $this->booking->refresh()->load('user', 'schedule.trip', 'passengers', 'installmentPayments');
    }

    public static function mailables(): array
    {
        return [
            'booking-created' => [fn ($t) => new BookingCreatedMail($t->booking)],
            'booking-cancelled' => [fn ($t) => new BookingCancelledMail($t->booking, 'ผู้จัดยกเลิกรอบเดินทาง')],
            'payment-confirmed' => [fn ($t) => new PaymentConfirmedMail($t->booking)],
            'deposit-paid' => [fn ($t) => new DepositPaidMail($t->booking)],
            'balance-paid' => [fn ($t) => new BalancePaidMail($t->booking)],
            'balance-due-reminder' => [fn ($t) => new BalanceDueReminderMail($t->booking)],
            'installment-paid' => [fn ($t) => new InstallmentPaidMail($t->booking, $t->installment)],
            'installment-due-overdue' => [fn ($t) => new InstallmentDueReminderMail($t->booking, $t->installment, 'overdue')],
            'installment-due-today' => [fn ($t) => new InstallmentDueReminderMail($t->booking, $t->installment, 'due_today')],
            'installment-due-soon' => [fn ($t) => new InstallmentDueReminderMail($t->booking, $t->installment, 'due_soon')],
            'status-confirmed' => [fn ($t) => new BookingStatusChangedMail($t->booking, 'confirmed')],
            'status-cancelled' => [fn ($t) => new BookingStatusChangedMail($t->booking, 'cancelled')],
            'status-refunded' => [fn ($t) => new BookingStatusChangedMail($t->booking, 'refunded')],
            'status-pending' => [fn ($t) => new BookingStatusChangedMail($t->booking, 'pending')],
            'underfilled' => [fn ($t) => new TripUnderfilledWarningMail($t->booking, 7, 3, 8)],
            'welcome' => [fn ($t) => new WelcomeRegistrationMail($t->booking->user)],
            'welcome-with-verify' => [fn ($t) => new WelcomeRegistrationMail($t->booking->user, 'https://luilaykhao.test/verify-email/1/abc?signature=xyz')],
            'password-reset' => [fn ($t) => new PasswordResetMail($t->booking->user, 'https://luilaykhao.test/reset-password?token=abc&email=a%40b.com', 60)],
            'email-verification' => [fn ($t) => new EmailVerificationMail($t->booking->user, 'https://luilaykhao.test/verify-email/1/abc?signature=xyz')],
            'admin-new-booking' => [fn ($t) => new AdminNewBookingMail($t->booking)],
            'admin-payment-received' => [fn ($t) => new AdminPaymentReceivedMail($t->booking, 'deposit')],
            'gift-purchased' => [function ($t) {
                $t->booking->forceFill([
                    'is_gift' => true,
                    'gift_code' => 'K7XPQ2MB',
                    'gift_from_name' => 'พี่หมี',
                    'gift_message' => 'สุขสันต์วันเกิดนะ',
                ]);

                return new GiftPurchasedMail($t->booking);
            }],
            'gift-claimed' => [function ($t) {
                $t->booking->forceFill([
                    'is_gift' => true,
                    'gift_code' => 'K7XPQ2MB',
                    'gift_from_name' => 'พี่หมี',
                    'gifted_by_user_id' => $t->booking->user_id,
                ])->setRelation('giftedBy', $t->booking->user);

                return new GiftClaimedMail($t->booking, 'น้องมายด์');
            }],
        ];
    }

    #[DataProvider('mailables')]
    public function test_template_renders_on_the_dark_theme(callable $make): void
    {
        $html = $make($this)->render();

        // The dark shell must survive: page background, card background, rounded wrapper.
        $this->assertStringContainsString('#0a0a0a', $html);
        $this->assertStringContainsString('background: #141414', $html);
        $this->assertStringContainsString('border-radius: 18px', $html);

        // No template may reintroduce a light panel via an inline style.
        foreach (['#ffffff', '#f8fafc', '#f0fdf4', '#fffbeb', '#eff6ff', '#fef2f2', '#f6f8fa'] as $lightHex) {
            $this->assertStringNotContainsString(
                'style="background:'.$lightHex,
                $html,
                "A light panel ({$lightHex}) leaked back into the dark theme."
            );
        }

        // Blade variables must all resolve — an unset one would print as literal syntax.
        $this->assertStringNotContainsString('{{', $html);
    }
}
