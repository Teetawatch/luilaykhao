<?php

namespace App\Console\Commands;

use App\Models\InstallmentPayment;
use App\Models\SmartNotification;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendInstallmentReminders extends Command
{
    private const DUE_SOON_DAYS = 2;

    protected $signature   = 'installment:remind';
    protected $description = 'แจ้งเตือนผ่อนชำระที่ใกล้ครบกำหนด และทำเครื่องหมาย overdue';

    public function handle(SmsService $smsService): void
    {
        $today = now()->toDateString();

        // ── 1. Mark overdue ─────────────────────────────────────
        $overdue = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', '<', $today)
            ->whereHas('booking', fn ($query) => $query->where('payment_type', 'installment'))
            ->with('booking.user')
            ->get();

        foreach ($overdue as $ip) {
            $ip->update(['status' => 'overdue']);
            Log::warning('Installment overdue', [
                'booking_ref'    => $ip->booking->booking_ref ?? null,
                'installment_no' => $ip->installment_no,
                'amount'         => $ip->amount,
                'due_date'       => $ip->due_date?->toDateString(),
            ]);

            if ($ip->booking?->user_id) {
                $smsService->sendInstallmentReminder($ip, 'overdue');
                SmartNotification::send(
                    $ip->booking->user_id,
                    'installment_overdue',
                    'ค่างวดเลยกำหนดชำระ',
                    "งวดที่ {$ip->installment_no} ของเลขการจอง {$ip->booking->booking_ref} เลยกำหนดชำระแล้ว",
                    [
                        'booking_ref' => $ip->booking->booking_ref,
                        'installment_no' => $ip->installment_no,
                        'route' => 'booking',
                    ],
                );
            }
        }

        // ── 2. Remind before due ─────────────────────────────────
        $upcoming = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', now()->addDays(self::DUE_SOON_DAYS)->toDateString())
            ->whereHas('booking', fn ($query) => $query->where('payment_type', 'installment'))
            ->with('booking.user')
            ->get();

        foreach ($upcoming as $ip) {
            Log::info('Installment due soon', [
                'booking_ref'    => $ip->booking->booking_ref ?? null,
                'installment_no' => $ip->installment_no,
                'amount'         => $ip->amount,
                'due_date'       => $ip->due_date?->toDateString(),
                'days_before'    => self::DUE_SOON_DAYS,
                'user_email'     => $ip->booking->user->email ?? null,
            ]);

            if ($ip->booking?->user_id) {
                $smsService->sendInstallmentReminder($ip, 'due_soon');
                SmartNotification::send(
                    $ip->booking->user_id,
                    'installment_due_soon',
                    'ใกล้ถึงกำหนดชำระค่างวด',
                    "งวดที่ {$ip->installment_no} ของเลขการจอง {$ip->booking->booking_ref} จะครบกำหนดในอีก " . self::DUE_SOON_DAYS . " วัน",
                    [
                        'booking_ref' => $ip->booking->booking_ref,
                        'installment_no' => $ip->installment_no,
                        'days_before' => self::DUE_SOON_DAYS,
                        'route' => 'booking',
                    ],
                );
            }
        }

        // ── 3. Remind on due date ────────────────────────────────
        $dueToday = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', $today)
            ->whereHas('booking', fn ($query) => $query->where('payment_type', 'installment'))
            ->with('booking.user')
            ->get();

        foreach ($dueToday as $ip) {
            Log::info('Installment due TODAY', [
                'booking_ref'    => $ip->booking->booking_ref ?? null,
                'installment_no' => $ip->installment_no,
                'amount'         => $ip->amount,
                'user_email'     => $ip->booking->user->email ?? null,
            ]);

            if ($ip->booking?->user_id) {
                $smsService->sendInstallmentReminder($ip, 'due_today');
                SmartNotification::send(
                    $ip->booking->user_id,
                    'installment_due_today',
                    'ถึงกำหนดชำระค่างวดวันนี้',
                    "งวดที่ {$ip->installment_no} ของเลขการจอง {$ip->booking->booking_ref} ครบกำหนดชำระวันนี้",
                    [
                        'booking_ref' => $ip->booking->booking_ref,
                        'installment_no' => $ip->installment_no,
                        'route' => 'booking',
                    ],
                );
            }
        }

        $this->info("ตรวจสอบเสร็จ: overdue={$overdue->count()}, due_today={$dueToday->count()}, upcoming={$upcoming->count()}");
    }
}
