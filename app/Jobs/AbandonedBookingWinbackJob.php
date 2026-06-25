<?php

namespace App\Jobs;

use App\Services\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AbandonedBookingWinbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(BookingService $bookingService): void
    {
        $sent = $bookingService->sendAbandonedWinbacks();

        if ($sent > 0) {
            Log::info('AbandonedBookingWinbackJob completed', ['sent' => $sent]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AbandonedBookingWinbackJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
