<?php

namespace App\Jobs;

use App\Services\BroadcastNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Periodic sweep that blasts an "almost sold out" push for any bookable round
 * that has dipped to a low seat count — once per round (see the dedupe ledger).
 */
class BroadcastLowSeatsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(BroadcastNotificationService $broadcast): void
    {
        $broadcast->sweepLowSeats();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BroadcastLowSeatsJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
