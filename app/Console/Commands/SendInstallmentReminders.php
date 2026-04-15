<?php

namespace App\Console\Commands;

use App\Models\InstallmentPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendInstallmentReminders extends Command
{
    protected $signature   = 'installment:remind';
    protected $description = 'แจ้งเตือนผ่อนชำระที่ใกล้ครบกำหนด และทำเครื่องหมาย overdue';

    public function handle(): void
    {
        $today = now()->toDateString();

        // ── 1. Mark overdue ─────────────────────────────────────
        $overdue = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', '<', $today)
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

            // TODO: send email/SMS to $ip->booking->user
        }

        // ── 2. Remind 3 days before due ──────────────────────────
        $upcoming = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', now()->addDays(3)->toDateString())
            ->with('booking.user')
            ->get();

        foreach ($upcoming as $ip) {
            Log::info('Installment due in 3 days', [
                'booking_ref'    => $ip->booking->booking_ref ?? null,
                'installment_no' => $ip->installment_no,
                'amount'         => $ip->amount,
                'due_date'       => $ip->due_date?->toDateString(),
                'user_email'     => $ip->booking->user->email ?? null,
            ]);

            // TODO: send email/SMS to $ip->booking->user
        }

        // ── 3. Remind on due date ────────────────────────────────
        $dueToday = InstallmentPayment::where('status', 'pending')
            ->whereDate('due_date', $today)
            ->with('booking.user')
            ->get();

        foreach ($dueToday as $ip) {
            Log::info('Installment due TODAY', [
                'booking_ref'    => $ip->booking->booking_ref ?? null,
                'installment_no' => $ip->installment_no,
                'amount'         => $ip->amount,
                'user_email'     => $ip->booking->user->email ?? null,
            ]);

            // TODO: send email/SMS to $ip->booking->user
        }

        $this->info("ตรวจสอบเสร็จ: overdue={$overdue->count()}, due_today={$dueToday->count()}, upcoming={$upcoming->count()}");
    }
}
