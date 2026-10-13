<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One slice of a push broadcast: sends to at most
 * [SendBroadcastNotificationJob::CHUNK_SIZE] users.
 *
 * Each send calls FCM inline, so one job covering the whole base ran into
 * Horizon's 60 s timeout and was killed partway (2026-10-12) — whoever came
 * after the cut never got the message, with nothing to say who. A slice this
 * small finishes in seconds, and a failure costs one slice, not the rest.
 *
 * Slicing alone wasn't enough: sent one after another, a slice of 50 still
 * hit the timeout (2026-10-12/13), so a slice pushes its FCM calls in
 * parallel ([SmartNotification::sendMany]).
 *
 * Not retried: a slice that died halfway has already pushed to some of its
 * users, and a retry would push to them twice.
 */
class SendBroadcastNotificationChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $userIds,
        public string $type,
        public string $title,
        public string $body,
        public array $data = [],
        public ?int $dispatchId = null,
    ) {}

    public function handle(): void
    {
        SmartNotification::sendMany(
            $this->userIds,
            $this->type,
            $this->title,
            $this->body,
            $this->data,
            $this->dispatchId,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBroadcastNotificationChunkJob failed', [
            'type' => $this->type,
            'dispatch_id' => $this->dispatchId,
            'users' => count($this->userIds),
            'error' => $exception->getMessage(),
        ]);
    }
}
