<?php

namespace App\Jobs;

use App\Mail\AdminFinanceCloseOverdueMail;
use App\Models\User;
use App\Services\ScheduleFinanceService;
use App\Support\SiteSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * เตือนทุกเช้าว่ายังมีรอบไหนค้างปิดงบอยู่บ้าง
 *
 * เงื่อนไขก่อนปิดงบกันไม่ให้ "ปิดมั่ว" ได้ก็จริง แต่ไม่ได้กันการ "ไม่ปิดเลย"
 * ซึ่งเป็นการละหลวมที่เกิดง่ายกว่ามาก งานนี้กับการบล็อกเปิดรอบใหม่คือสองอย่าง
 * ที่ทำให้ทุกรอบถูกปิดงบจริง ไม่ใช่แค่รอบที่มีคนนึกได้
 */
class SendFinanceCloseRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function handle(ScheduleFinanceService $financeService): void
    {
        $rounds = $financeService->overdueRounds(50);

        if ($rounds === []) {
            return;
        }

        // แอดมิน + คนที่ได้สิทธิ์การเงิน — คนที่ปิดงบได้จริงเท่านั้น
        $emails = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'finance']))
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();

        if ($emails === []) {
            return;
        }

        try {
            Mail::to($emails)->send(new AdminFinanceCloseOverdueMail(
                $rounds,
                SiteSettings::financeCloseGraceDays(),
                SiteSettings::financeBlocksNewRounds(),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send finance close reminder', [
                'overdue' => count($rounds),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
