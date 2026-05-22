<?php

namespace App\Jobs;

use App\Services\GroupPlanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExpireGroupPlansJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(GroupPlanService $groupPlanService): void
    {
        $expired = $groupPlanService->expireStale();

        if ($expired > 0) {
            Log::info('ExpireGroupPlansJob completed', ['expired' => $expired]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExpireGroupPlansJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
