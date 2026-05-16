<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendBalanceDueRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    private const REMIND_DAYS_BEFORE_DUE = [5, 2, 0];

    public function handle(MailService $mailService, SmsService $smsService): void
    {
        $totals = ['emailed' => 0, 'smsed' => 0];

        foreach (self::REMIND_DAYS_BEFORE_DUE as $daysBeforeDue) {
            $targetDate = now()->addDays($daysBeforeDue)->toDateString();

            $bookings = Booking::query()
                ->where('payment_type', 'deposit')
                ->where('status', 'confirmed')
                ->whereNull('balance_paid_at')
                ->whereDate('balance_due_at', $targetDate)
                ->with(['user', 'passengers', 'schedule.trip'])
                ->get();

            foreach ($bookings as $booking) {
                try {
                    $mailService->sendBalanceDueReminderEmail($booking);
                    $totals['emailed']++;
                } catch (\Throwable $e) {
                    Log::error('Failed to send balance due reminder email', [
                        'booking_ref'     => $booking->booking_ref,
                        'days_before_due' => $daysBeforeDue,
                        'error'           => $e->getMessage(),
                    ]);
                }

                try {
                    $smsService->sendBalanceDueReminder($booking);
                    $totals['smsed']++;
                } catch (\Throwable $e) {
                    Log::error('Failed to queue balance due reminder SMS', [
                        'booking_ref'     => $booking->booking_ref,
                        'days_before_due' => $daysBeforeDue,
                        'error'           => $e->getMessage(),
                    ]);
                }

                if ($booking->user_id) {
                    SmartNotification::send(
                        $booking->user_id,
                        'balance_due_reminder',
                        $daysBeforeDue === 0
                            ? 'ครบกำหนดชำระยอดส่วนที่เหลือวันนี้'
                            : "เหลือ {$daysBeforeDue} วัน ก่อนครบกำหนดชำระยอดส่วนที่เหลือ",
                        "เลขการจอง {$booking->booking_ref} กรุณาชำระยอดส่วนที่เหลือภายในวันที่ " . ($booking->balance_due_at?->format('d/m/Y') ?? '-'),
                        ['booking_ref' => $booking->booking_ref, 'days_before_due' => $daysBeforeDue, 'route' => 'booking'],
                    );
                }
            }
        }

        Log::info('SendBalanceDueRemindersJob completed', $totals);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBalanceDueRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
