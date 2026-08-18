<?php

namespace App\Services;

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
use App\Mail\PassportExpiringMail;
use App\Mail\PassportInfoNeededMail;
use App\Mail\PasswordResetMail;
use App\Mail\PaymentConfirmedMail;
use App\Mail\TripUnderfilledWarningMail;
use App\Mail\WelcomeRegistrationMail;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\Receipt;
use App\Models\User;
use App\Support\AccountLinks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    public function __construct(private ReceiptService $receipts) {}

    /**
     * ออกใบเสร็จให้การจอง (idempotent) แบบไม่ให้ล้มอีเมลถ้าออกใบเสร็จพลาด
     */
    private function issueReceipt(Booking $booking, string $kind, ?float $amount = null): ?Receipt
    {
        try {
            return $this->receipts->issueForBooking($booking, $kind, $amount);
        } catch (\Throwable $e) {
            Log::error('Failed to issue receipt', [
                'booking_ref' => $booking->booking_ref,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get admin email addresses for notifications.
     */
    private function getAdminEmails(): array
    {
        return User::role('admin')
            ->whereNotNull('email')
            ->pluck('email')
            ->toArray();
    }

    private function customerEmails(Booking $booking): array
    {
        $booking->loadMissing(['user', 'passengers']);

        $passengerEmails = $booking->passengers
            ->pluck('email')
            ->filter(fn ($email) => filled($email))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();

        if (! empty($passengerEmails)) {
            return $passengerEmails;
        }

        return collect([$booking->user?->email])
            ->filter(fn ($email) => filled($email))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();
    }

    private function sendToCustomerEmails(Booking $booking, callable $mailableFactory): void
    {
        foreach ($this->customerEmails($booking) as $email) {
            Mail::to($email)->send($mailableFactory());
        }
    }

    /**
     * Send welcome email to newly registered user.
     *
     * The verification link rides along inside this email rather than going out
     * as a second one — a signup that lands two emails in the same second reads
     * as a glitch, and the one people actually need to act on is this one.
     */
    public function sendWelcomeEmail(User $user, ?string $verifyUrl = null): void
    {
        try {
            Mail::to($user->email)->send(new WelcomeRegistrationMail($user, $verifyUrl));
        } catch (\Throwable $e) {
            Log::error('Failed to send welcome email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send the "choose a new password" link.
     *
     * Never lets a mail failure bubble: the forgot-password endpoint answers the
     * same way whether or not the address exists, and an exception here would
     * turn a 500 into an account-enumeration oracle.
     */
    public function sendPasswordResetEmail(User $user, string $token): void
    {
        try {
            $expires = (int) config('auth.passwords.users.expire', 60);

            Mail::to($user->email)->send(new PasswordResetMail(
                $user,
                AccountLinks::resetPassword($token, $user->email),
                $expires,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send password reset email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a standalone "verify your email" link — used by the resend button,
     * when the one folded into the welcome email has aged out.
     */
    public function sendEmailVerificationEmail(User $user): void
    {
        try {
            Mail::to($user->email)->send(new EmailVerificationMail(
                $user,
                AccountLinks::verifyEmail($user),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send email verification email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send booking created confirmation to customer + admin notification.
     */
    public function sendBookingCreatedEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            // ของขวัญ: ผู้ซื้อได้อีเมลเฉพาะที่แนบโค้ด+ลิงก์แชร์ แทนอีเมลจองปกติ
            // (ผู้เดินทางเป็นผู้รับที่ยังไม่กรอกอีเมล — ส่งถึงผู้ซื้อโดยตรง)
            if ($booking->is_gift) {
                $this->sendGiftPurchasedEmail($booking);
            } else {
                $this->sendToCustomerEmails($booking, fn () => new BookingCreatedMail($booking));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send booking created email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Admin notification
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminNewBookingMail($booking));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin new booking email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ขอข้อมูลพาสปอร์ตที่ยังขาดของทริปต่างประเทศ
     *
     * ใช้กับการจองที่เข้ามาจากช่องทางที่ยังไม่มีช่องให้กรอก — แอปรุ่นก่อนหน้าที่
     * ลูกค้าอีกจำนวนหนึ่งยังใช้อยู่ ลิงก์ในอีเมลจึงเป็นทางเดียวที่คนกลุ่มนี้กรอกได้
     * โดยไม่ต้องรออัปเดตแอป
     */
    public function sendPassportInfoNeededEmail(Booking $booking): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);

        if (! $booking->needsPassportInfo()) {
            return;
        }

        try {
            $url = $booking->passportUrl();

            $this->sendToCustomerEmails($booking, fn () => new PassportInfoNeededMail($booking, $url));
        } catch (\Throwable $e) {
            Log::error('Failed to send passport info needed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * เตือนว่าพาสปอร์ตที่กรอกไว้แล้วจะหมดอายุเร็วกว่าเกณฑ์ 6 เดือน
     *
     * ต่างจาก sendPassportInfoNeededEmail ที่ทวงของที่ยังไม่ได้กรอก — ฉบับนี้
     * ของครบแล้วแต่เล่มใช้ไม่ได้ ต้องบอกให้ทันไปต่อเล่ม
     */
    public function sendPassportExpiringEmail(Booking $booking, int $daysUntilDeparture): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);

        if (app(TravelDocumentService::class)->expiringTooSoon($booking)->isEmpty()) {
            return;
        }

        try {
            $url = $booking->passportUrl();

            $this->sendToCustomerEmails(
                $booking,
                fn () => new PassportExpiringMail($booking, $url, $daysUntilDeparture),
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send passport expiring email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ส่งอีเมลของขวัญให้ผู้ซื้อ — แนบโค้ดและลิงก์แชร์ไว้ส่งต่อให้ผู้รับ
     */
    public function sendGiftPurchasedEmail(Booking $booking): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);

        $email = $booking->user?->email;
        if (! filled($email)) {
            return;
        }

        try {
            Mail::to($email)->send(new GiftPurchasedMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send gift purchased email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ส่งอีเมลให้ผู้ให้ของขวัญเมื่อผู้รับกดรับแล้ว
     */
    public function sendGiftClaimedEmail(Booking $booking, string $recipientName): void
    {
        $booking->loadMissing(['giftedBy', 'schedule.trip']);

        $email = $booking->giftedBy?->email;
        if (! filled($email)) {
            return;
        }

        try {
            Mail::to($email)->send(new GiftClaimedMail($booking, $recipientName));
        } catch (\Throwable $e) {
            Log::error('Failed to send gift claimed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send payment confirmed email to customer + admin notification.
     */
    public function sendPaymentConfirmedEmail(Booking $booking, string $paymentType = 'full'): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers', 'installmentPayments']);

        // ใบเสร็จ: งวดแรก = ยอดงวดแรก, เต็มจำนวน = ยอดที่ชำระ
        $kind = $paymentType === 'installment' ? 'installment' : 'full';
        $receipt = $this->issueReceipt($booking, $kind, (float) $booking->paid_amount);

        try {
            // Customer email
            $this->sendToCustomerEmails($booking, fn () => new PaymentConfirmedMail($booking, $paymentType, $receipt));
        } catch (\Throwable $e) {
            Log::error('Failed to send payment confirmed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Admin notification
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminPaymentReceivedMail($booking, $paymentType));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin payment received email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send booking cancellation email to customer.
     */
    public function sendBookingCancelledEmail(Booking $booking, ?string $reason = null): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new BookingCancelledMail($booking, $reason));
        } catch (\Throwable $e) {
            Log::error('Failed to send booking cancelled email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send booking status changed email (admin-triggered).
     */
    public function sendBookingStatusChangedEmail(Booking $booking, string $newStatus): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new BookingStatusChangedMail($booking, $newStatus));
        } catch (\Throwable $e) {
            Log::error('Failed to send booking status changed email', [
                'booking_ref' => $booking->booking_ref,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send installment payment confirmation email.
     */
    public function sendInstallmentPaidEmail(Booking $booking, InstallmentPayment $installment): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers', 'installmentPayments']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new InstallmentPaidMail($booking, $installment));
        } catch (\Throwable $e) {
            Log::error('Failed to send installment paid email', [
                'booking_ref' => $booking->booking_ref,
                'installment_no' => $installment->installment_no,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send deposit-paid confirmation email after the customer pays the deposit.
     */
    public function sendDepositPaidEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers', 'seats', 'splitShares']);

        // จ่ายแบบแบ่งกลุ่มเก็บ payment_type เป็น deposit — แยกด้วยการมีส่วนแบ่ง
        $kind = $booking->splitShares()->exists() ? 'split' : 'deposit';
        $receipt = $this->issueReceipt($booking, $kind, (float) $booking->deposit_amount);

        try {
            $this->sendToCustomerEmails($booking, fn () => new DepositPaidMail($booking, $receipt));
        } catch (\Throwable $e) {
            Log::error('Failed to send deposit paid email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminPaymentReceivedMail($booking, 'deposit'));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin deposit received email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send installment due reminder email (due_soon | due_today | overdue).
     */
    public function sendInstallmentDueReminderEmail(Booking $booking, InstallmentPayment $installment, string $reminderType): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers']);
        $booking->ensurePaymentToken();

        try {
            $this->sendToCustomerEmails($booking, fn () => new InstallmentDueReminderMail($booking, $installment, $reminderType));
        } catch (\Throwable $e) {
            Log::error('Failed to send installment due reminder email', [
                'booking_ref' => $booking->booking_ref,
                'installment_no' => $installment->installment_no,
                'reminder_type' => $reminderType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send balance-due reminder email (called by scheduled command).
     */
    public function sendBalanceDueReminderEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);
        $booking->ensurePaymentToken();

        try {
            $this->sendToCustomerEmails($booking, fn () => new BalanceDueReminderMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send balance due reminder email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an "trip may be cancelled" warning email — the round is close to
     * departure but still below the guaranteed minimum number of booked seats.
     */
    public function sendTripUnderfilledWarningEmail(Booking $booking, int $daysBefore, int $bookedSeats, int $minSeats): void
    {
        $booking->loadMissing(['user', 'schedule.trip', 'passengers', 'pickupPoint']);

        try {
            $this->sendToCustomerEmails(
                $booking,
                fn () => new TripUnderfilledWarningMail($booking, $daysBefore, $bookedSeats, $minSeats),
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send trip underfilled warning email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send balance-paid confirmation email after the customer settles the remaining balance.
     */
    public function sendBalancePaidEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        $receipt = $this->issueReceipt($booking, 'balance', (float) $booking->balance_amount);

        try {
            $this->sendToCustomerEmails($booking, fn () => new BalancePaidMail($booking, $receipt));
        } catch (\Throwable $e) {
            Log::error('Failed to send balance paid email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $adminEmails = $this->getAdminEmails();
            if (! empty($adminEmails)) {
                Mail::to($adminEmails)->send(new AdminPaymentReceivedMail($booking, 'balance'));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send admin balance received email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
