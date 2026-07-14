<?php

namespace App\Jobs;

use App\Models\SupportMessage;
use App\Services\FcmService;
use App\Services\SupportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * แจ้งเตือนอีกฝั่งเมื่อมีข้อความใหม่ในห้องช่วยเหลือ
 * ลูกค้าทัก → push หาทีมงานทุกคน; ทีมงานตอบ → push หาลูกค้าเจ้าของห้อง
 */
class SendSupportPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(
        public readonly int $messageId,
    ) {}

    public function handle(SupportService $support, FcmService $fcm): void
    {
        $message = SupportMessage::with('conversation.user', 'user')->find($this->messageId);
        if (! $message || ! $message->conversation || $message->sender_role === 'system') {
            return;
        }

        $preview = $support->previewText($message);

        if ($message->sender_role === 'customer') {
            $customer = $message->conversation->user;
            $senderName = $customer?->nickname ?: ($customer?->name ?? 'ลูกค้า');
            $title = '💬 ข้อความช่วยเหลือใหม่';
            $body = "$senderName: $preview";

            $recipientIds = $support->adminRecipientIds();
        } else {
            $senderName = $message->user?->nickname ?: ($message->user?->name ?? 'ทีมงาน');
            $title = '💬 ทีมงานลุยเลเขา';
            $body = "$senderName: $preview";

            $ownerId = $message->conversation->user_id;
            $recipientIds = $ownerId ? collect([$ownerId]) : collect();
        }

        foreach ($recipientIds as $userId) {
            try {
                $fcm->sendToUser((int) $userId, $title, $body, [
                    'type' => 'support_message',
                    'route' => 'support',
                    'conversation_id' => (string) $message->conversation_id,
                    'message_id' => (string) $message->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('SendSupportPushJob: ส่ง push ไม่สำเร็จ', [
                    'user_id' => $userId,
                    'message_id' => $this->messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
