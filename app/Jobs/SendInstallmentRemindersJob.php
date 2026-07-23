<?php

namespace App\Jobs;

use App\Models\InstallmentPayment;
use App\Models\SmartNotification;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendInstallmentRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const DUE_SOON_DAYS = 2;

    public function handle(SmsService $smsService, MailService $mailService): void
    {
        $today = now()->toDateString();

        // 1. Mark overdue + notify
        $overdue = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', '<', $today)
            ->whereHas('booking', fn ($q) => $q->where('payment_type', 'installment'))
            ->with('booking.user')
            ->get();

        foreach ($overdue as $ip) {
            $ip->update(['status' => 'overdue']);
            Log::warning('Installment overdue', [
                'booking_ref' => $ip->booking->booking_ref ?? null,
                'installment_no' => $ip->installment_no,
                'due_date' => $ip->due_date?->toDateString(),
            ]);

            if ($ip->booking?->user_id) {
                $mailService->sendInstallmentDueReminderEmail($ip->booking, $ip, 'overdue');
                $smsService->sendInstallmentReminder($ip, 'overdue');
                SmartNotification::send(
                    $ip->booking->user_id,
                    'installment_overdue',
                    'ค่างวดเลยกำหนดชำระ',
                    "งวดที่ {$ip->installment_no} ของเลขการจอง {$ip->booking->booking_ref} เลยกำหนดชำระแล้ว",
                    ['booking_ref' => $ip->booking->booking_ref, 'installment_no' => $ip->installment_no, 'route' => 'booking'],
                );
            }
        }

        // 2. Remind 2 days before due
        $upcoming = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', now()->addDays(self::DUE_SOON_DAYS)->toDateString())
            ->whereHas('booking', fn ($q) => $q->where('payment_type', 'installment'))
            ->with('booking.user')
            ->get();

        foreach ($upcoming as $ip) {
            if ($ip->booking?->user_id) {
                $mailService->sendInstallmentDueReminderEmail($ip->booking, $ip, 'due_soon');
                $smsService->sendInstallmentReminder($ip, 'due_soon');
                SmartNotification::send(
                    $ip->booking->user_id,
                    'installment_due_soon',
                    'ใกล้ถึงกำหนดชำระค่างวด',
                    "งวดที่ {$ip->installment_no} ของเลขการจอง {$ip->booking->booking_ref} จะครบกำหนดในอีก ".self::DUE_SOON_DAYS.' วัน',
                    ['booking_ref' => $ip->booking->booking_ref, 'installment_no' => $ip->installment_no, 'days_before' => self::DUE_SOON_DAYS, 'route' => 'booking'],
                );
            }
        }

        // 3. Remind on due date
        $dueToday = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', $today)
            ->whereHas('booking', fn ($q) => $q->where('payment_type', 'installment'))
            ->with('booking.user')
            ->get();

        foreach ($dueToday as $ip) {
            if ($ip->booking?->user_id) {
                $mailService->sendInstallmentDueReminderEmail($ip->booking, $ip, 'due_today');
                $smsService->sendInstallmentReminder($ip, 'due_today');
                SmartNotification::send(
                    $ip->booking->user_id,
                    'installment_due_today',
                    'ถึงกำหนดชำระค่างวดวันนี้',
                    "งวดที่ {$ip->installment_no} ของเลขการจอง {$ip->booking->booking_ref} ครบกำหนดชำระวันนี้",
                    ['booking_ref' => $ip->booking->booking_ref, 'installment_no' => $ip->installment_no, 'route' => 'booking'],
                );
            }
        }

        Log::info('SendInstallmentRemindersJob completed', [
            'overdue' => $overdue->count(),
            'due_today' => $dueToday->count(),
            'upcoming' => $upcoming->count(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendInstallmentRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
