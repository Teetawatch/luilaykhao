<?php

namespace App\Jobs;

use App\Services\PartnerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * อนุมัติคอมพาร์ทเนอร์ของรอบที่เดินทางกลับแล้ว — ดู PartnerService::approveDue()
 */
class ApprovePartnerCommissionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(PartnerService $partners): void
    {
        $approved = $partners->approveDue();

        if ($approved > 0) {
            Log::info('ApprovePartnerCommissionsJob completed', ['approved' => $approved]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ApprovePartnerCommissionsJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
