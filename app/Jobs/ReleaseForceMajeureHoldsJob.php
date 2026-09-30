<?php

namespace App\Jobs;

use App\Services\ForceMajeureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ปิดการกันที่นั่งให้คนที่ถูกเลื่อนเพราะเหตุสุดวิสัยที่หมดเวลาแล้ว (48 ชม.) และให้
 * คิวรอของรอบนั้นได้สิทธิ์ต่อทันที — ทุก 5 นาที ดู ForceMajeureService
 */
class ReleaseForceMajeureHoldsJob implements ShouldQueue
{
    use Queueable;

    public function handle(ForceMajeureService $forceMajeure): void
    {
        $released = $forceMajeure->releaseExpiredHolds();

        if ($released > 0) {
            Log::info('ReleaseForceMajeureHoldsJob released holds', ['count' => $released]);
        }
    }
}
