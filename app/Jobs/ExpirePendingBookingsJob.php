<?php

namespace App\Jobs;

use App\Services\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExpirePendingBookingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(BookingService $bookingService): void
    {
        $expired = $bookingService->expireStalePendingBookings();

        if ($expired > 0) {
            Log::info('ExpirePendingBookingsJob completed', ['expired' => $expired]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExpirePendingBookingsJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
