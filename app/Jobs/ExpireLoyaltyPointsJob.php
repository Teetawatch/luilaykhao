<?php

namespace App\Jobs;

use App\Services\LoyaltyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ล้างแต้มที่หมดอายุแล้วออกจากบัญชี — รันวันละครั้ง
 *
 * แต้มมีอายุ 24 เดือนนับจากวันที่ได้รับ ลูกค้าจะได้รับการเตือนล่วงหน้า 30 วัน
 * โดย WarnExpiringLoyaltyPointsJob ก่อนถึงวันนี้เสมอ
 */
class ExpireLoyaltyPointsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(LoyaltyService $loyalty): void
    {
        $expired = $loyalty->expireDuePoints();

        if ($expired > 0) {
            Log::info("Loyalty: expired {$expired} points");
        }
    }
}
