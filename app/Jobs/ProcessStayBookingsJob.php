<?php

namespace App\Jobs;

use App\Services\StayBookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * งานประจำของใบจองที่พัก (ทุก 5 นาที):
 * ปิดใบที่ไม่จ่ายตามเวลา (ห้องกลับมาขาย) · ปิดคำขอที่ไม่มีใครตอบ ·
 * ปิดใบที่เข้าพักจบแล้ว (ถึงรอบจ่ายเจ้าของ + ชวนรีวิว)
 */
class ProcessStayBookingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(StayBookingService $stays): void
    {
        $expired = $stays->expireUnpaid();
        $staleRequests = $stays->expireStaleRequests();
        $completed = $stays->completeFinished();

        if ($expired + $staleRequests + $completed > 0) {
            Log::info('ProcessStayBookingsJob completed', compact('expired', 'staleRequests', 'completed'));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessStayBookingsJob failed', ['error' => $exception->getMessage()]);
    }
}
