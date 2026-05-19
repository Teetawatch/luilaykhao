<?php

namespace App\Jobs;

use App\Services\WaitlistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExpireWaitlistOffersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(WaitlistService $waitlistService): void
    {
        $result = $waitlistService->expireStaleOffers();

        Log::info('ExpireWaitlistOffersJob completed', $result);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExpireWaitlistOffersJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
