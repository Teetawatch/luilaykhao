<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Services\ChatService;
use App\Services\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendChatPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(
        public readonly int $messageId,
        public readonly int $senderUserId,
    ) {}

    public function handle(ChatService $chatService, FcmService $fcm): void
    {
        $message = ChatMessage::with('schedule.trip', 'user')->find($this->messageId);
        if (! $message || ! $message->schedule) {
            return;
        }

        $tripTitle = $message->schedule->trip?->title ?? 'ทริปของคุณ';
        $senderName = $message->user?->nickname
            ?: ($message->user?->name ?? 'ทีมงาน');
        $preview = filled($message->body)
            ? Str::limit($message->body, 120)
            : ($message->image_path ? '📷 ส่งรูปภาพ' : '');

        $recipientIds = $chatService->pushRecipientIds($message->schedule)
            ->reject(fn ($id) => (int) $id === $this->senderUserId);

        foreach ($recipientIds as $userId) {
            try {
                $fcm->sendToUser(
                    (int) $userId,
                    "💬 $tripTitle",
                    "$senderName: $preview",
                    [
                        'type' => 'chat_message',
                        'route' => 'chat',
                        'schedule_id' => (string) $message->schedule_id,
                        'message_id' => (string) $message->id,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('SendChatPushJob: ส่ง push ไม่สำเร็จ', [
                    'user_id' => $userId,
                    'message_id' => $this->messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
