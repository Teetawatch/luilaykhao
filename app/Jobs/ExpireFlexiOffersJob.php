<?php

namespace App\Jobs;

use App\Services\FlexiDepartureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ปิดข้อเสนอ Flexi-Price (Go Together) ที่เลยเส้นตายตอบรับแต่ยังไม่ครบทุกคน
 * → expired แล้วแจ้งลูกค้า รันตามกำหนดเวลา (mirrors ExpireWaitlistOffersJob)
 */
class ExpireFlexiOffersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(FlexiDepartureService $flexi): void
    {
        $expired = $flexi->expireStale();

        if ($expired > 0) {
            Log::info('ExpireFlexiOffersJob expired offers', ['count' => $expired]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExpireFlexiOffersJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
