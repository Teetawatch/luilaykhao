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
use App\Mail\InstallmentDueReminderMail;
use App\Mail\InstallmentPaidMail;
use App\Mail\PaymentConfirmedMail;
use App\Mail\WelcomeRegistrationMail;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
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

        if (!empty($passengerEmails)) {
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
     */
    public function sendWelcomeEmail(User $user): void
    {
        try {
            Mail::to($user->email)->send(new WelcomeRegistrationMail($user));
        } catch (\Throwable $e) {
            Log::error('Failed to send welcome email', [
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
            // Customer email
            $this->sendToCustomerEmails($booking, fn () => new BookingCreatedMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send booking created email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Admin notification
            $adminEmails = $this->getAdminEmails();
            if (!empty($adminEmails)) {
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
     * Send payment confirmed email to customer + admin notification.
     */
    public function sendPaymentConfirmedEmail(Booking $booking, string $paymentType = 'full'): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers', 'installmentPayments']);

        try {
            // Customer email
            $this->sendToCustomerEmails($booking, fn () => new PaymentConfirmedMail($booking, $paymentType));
        } catch (\Throwable $e) {
            Log::error('Failed to send payment confirmed email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Admin notification
            $adminEmails = $this->getAdminEmails();
            if (!empty($adminEmails)) {
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
        $booking->load(['user', 'schedule.trip', 'passengers', 'seats']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new DepositPaidMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send deposit paid email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $adminEmails = $this->getAdminEmails();
            if (!empty($adminEmails)) {
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
                'booking_ref'    => $booking->booking_ref,
                'installment_no' => $installment->installment_no,
                'reminder_type'  => $reminderType,
                'error'          => $e->getMessage(),
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
     * Send balance-paid confirmation email after the customer settles the remaining balance.
     */
    public function sendBalancePaidEmail(Booking $booking): void
    {
        $booking->load(['user', 'schedule.trip', 'passengers']);

        try {
            $this->sendToCustomerEmails($booking, fn () => new BalancePaidMail($booking));
        } catch (\Throwable $e) {
            Log::error('Failed to send balance paid email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $adminEmails = $this->getAdminEmails();
            if (!empty($adminEmails)) {
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
