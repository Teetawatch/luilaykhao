<?php

namespace App\Jobs;

use App\Models\FcmToken;
use App\Models\SmartNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fans an automatic marketing broadcast out to every reachable customer —
 * those who have an active FCM token and haven't opted out of marketing pushes.
 * Each recipient gets a SmartNotification (DB row + FCM), so the message also
 * lands in their in-app inbox and badge, not just the push tray.
 *
 * Dispatched by [\App\Services\BroadcastNotificationService] after it has
 * already claimed the dedupe key, so this job only deals with delivery.
 */
class SendBroadcastNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(): void
    {
        $sent = 0;

        // Reachable audience = users with at least one active token who allow
        // marketing pushes. Chunk so a large customer base doesn't blow memory.
        User::query()
            ->where('marketing_push_enabled', true)
            ->whereIn('id', FcmToken::where('is_active', true)->select('user_id'))
            ->select('id')
            ->chunkById(500, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    SmartNotification::send(
                        $user->id,
                        $this->type,
                        $this->title,
                        $this->body,
                        $this->data,
                    );
                    $sent++;
                }
            });

        Log::info('SendBroadcastNotificationJob completed', [
            'type' => $this->type,
            'recipients' => $sent,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBroadcastNotificationJob failed', [
            'type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }
}
