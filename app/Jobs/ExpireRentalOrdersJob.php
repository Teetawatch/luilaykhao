<?php

namespace App\Jobs;

use App\Services\RentalOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ถอดอุปกรณ์ที่ลูกค้าสั่งเช่าเองแต่ไม่จ่ายก่อนปิดรับออกจากใบจอง — ดู RentalOrderService
 */
class ExpireRentalOrdersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(RentalOrderService $rentals): void
    {
        $result = $rentals->expireOverdue();

        if ($result['paid'] > 0 || $result['cancelled'] > 0) {
            Log::info('ExpireRentalOrdersJob completed', $result);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExpireRentalOrdersJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
