<?php

namespace App\Jobs;

use App\Models\TripSchedule;
use App\Services\BroadcastNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Launches flash sales that were scheduled to start later. A sale with a future
 * `flash_sale_starts_at` stays dormant (flashSaleActive() is false → normal
 * price, no push) until this job runs after its start time and announces it.
 *
 * broadcastFlashSale() self-guards on flashSaleActive() and dedupes on a per-sale
 * ledger key, so a sale is announced exactly once the moment it goes live — and
 * re-running this job (or an immediate, non-scheduled sale already announced by
 * the observer) never double-sends.
 */
class StartScheduledFlashSalesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(BroadcastNotificationService $broadcast): void
    {
        $schedules = TripSchedule::flashSaleJustStarted()
            ->with('trip')
            ->get();

        $announced = 0;

        foreach ($schedules as $schedule) {
            if (! $schedule->flashSaleActive()) {
                continue;
            }

            $broadcast->broadcastFlashSale($schedule);
            $announced++;
        }

        if ($announced > 0) {
            Log::info('StartScheduledFlashSalesJob announced flash sales', ['count' => $announced]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StartScheduledFlashSalesJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
