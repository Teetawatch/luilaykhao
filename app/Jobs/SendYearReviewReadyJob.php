<?php

namespace App\Jobs;

use App\Services\YearReviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * บอกทุกคนที่มีเหรียญในปีนี้ว่า "สรุปปีของคุณพร้อมแล้ว" — ครั้งเดียวต่อคนต่อปี
 * (กันซ้ำใน YearReviewService) รันปลายเดือนธันวาคม
 */
class SendYearReviewReadyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public int $timeout = 600;

    public function handle(YearReviewService $reviews): void
    {
        Log::info('SendYearReviewReadyJob completed', ['sent' => $reviews->notifyYearReady()]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendYearReviewReadyJob failed permanently', ['error' => $exception->getMessage()]);
    }
}
