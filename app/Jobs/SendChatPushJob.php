<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\UserBlock;
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

    /**
     * @param  array<int>  $mentionedUserIds
     */
    public function __construct(
        public readonly int $messageId,
        public readonly int $senderUserId,
        public readonly array $mentionedUserIds = [],
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

        $mentioned = collect($this->mentionedUserIds)->map(fn ($id) => (int) $id);

        // คนที่บล็อกผู้ส่งไว้ (หรือถูกผู้ส่งบล็อก) ไม่ควรได้รับ push ของข้อความนี้ —
        // ข้อความที่เขาเปิดเข้าไปแล้วมองไม่เห็น ไม่ควรเด้งเตือนตั้งแต่แรก
        $blockedPairIds = UserBlock::where('blocker_id', $this->senderUserId)
            ->orWhere('blocked_id', $this->senderUserId)
            ->get(['blocker_id', 'blocked_id'])
            ->map(fn (UserBlock $b) => $b->blocker_id === $this->senderUserId ? $b->blocked_id : $b->blocker_id)
            ->unique();

        $recipientIds = $chatService->pushRecipientIds($message->schedule)
            ->reject(fn ($id) => (int) $id === $this->senderUserId)
            ->reject(fn ($id) => $blockedPairIds->contains((int) $id));

        foreach ($recipientIds as $userId) {
            // Mentioned members get a more salient "you were mentioned" push
            // instead of the regular new-message one.
            $isMention = $mentioned->contains((int) $userId);

            try {
                $fcm->sendToUser(
                    (int) $userId,
                    $isMention ? "📣 $tripTitle" : "💬 $tripTitle",
                    $isMention
                        ? "$senderName กล่าวถึงคุณ: $preview"
                        : "$senderName: $preview",
                    [
                        'type' => 'chat_message',
                        'route' => 'chat',
                        'schedule_id' => (string) $message->schedule_id,
                        'message_id' => (string) $message->id,
                        'mention' => $isMention ? '1' : '0',
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
