<?php

namespace App\Jobs;

use App\Services\TripAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTripAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(TripAlertService $tripAlertService): void
    {
        $tripAlertService->processAll();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessTripAlertsJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
