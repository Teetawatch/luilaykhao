<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBalanceDueReminders extends Command
{
    private const REMIND_DAYS_BEFORE_DUE = [5, 2, 0];

    protected $signature = 'deposit:remind-balance';
    protected $description = 'แจ้งเตือนชำระเงินส่วนที่เหลือสำหรับการจองที่จ่ายมัดจำไว้ (ก่อนเดินทาง 15 วัน)';

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
                        'booking_ref' => $booking->booking_ref,
                        'days_before_due' => $daysBeforeDue,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $smsService->sendBalanceDueReminder($booking);
                    $totals['smsed']++;
                } catch (\Throwable $e) {
                    Log::error('Failed to queue balance due reminder SMS', [
                        'booking_ref' => $booking->booking_ref,
                        'days_before_due' => $daysBeforeDue,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($booking->user_id) {
                    SmartNotification::send(
                        $booking->user_id,
                        'balance_due_reminder',
                        $daysBeforeDue === 0 ? 'ครบกำหนดชำระยอดส่วนที่เหลือวันนี้' : "เหลือ {$daysBeforeDue} วัน ก่อนครบกำหนดชำระยอดส่วนที่เหลือ",
                        "เลขการจอง {$booking->booking_ref} กรุณาชำระยอดส่วนที่เหลือภายในวันที่ " . ($booking->balance_due_at?->format('d/m/Y') ?? '-'),
                        [
                            'booking_ref' => $booking->booking_ref,
                            'days_before_due' => $daysBeforeDue,
                            'route' => 'booking',
                        ],
                    );
                }
            }
        }

        $this->info("ส่งแจ้งเตือนยอดส่วนที่เหลือ: emails={$totals['emailed']}, sms={$totals['smsed']}");
    }
}
