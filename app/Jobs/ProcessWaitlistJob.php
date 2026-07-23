<?php

namespace App\Jobs;

use App\Services\WaitlistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWaitlistJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $scheduleId) {}

    public function handle(WaitlistService $waitlistService): void
    {
        $notified = $waitlistService->processSchedule($this->scheduleId);

        if ($notified > 0) {
            Log::info('ProcessWaitlistJob: แจ้งเตือนผู้รอคิวสำเร็จ', [
                'schedule_id' => $this->scheduleId,
                'notified' => $notified,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessWaitlistJob failed', [
            'schedule_id' => $this->scheduleId,
            'error' => $exception->getMessage(),
        ]);
    }
}
