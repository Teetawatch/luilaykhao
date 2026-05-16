<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendPendingSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private int $limit = 100) {}

    public function handle(SmsService $smsService): void
    {
        $sent = $smsService->sendPending($this->limit);
        Log::info('SendPendingSmsJob completed', ['sent' => $sent]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendPendingSmsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
