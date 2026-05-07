<?php

namespace App\Services;

use App\Mail\AdminNewBookingMail;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingCreatedMail;
use App\Mail\BookingStatusChangedMail;
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

        return collect([$booking->user?->email])
            ->merge($booking->passengers->pluck('email'))
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
}
