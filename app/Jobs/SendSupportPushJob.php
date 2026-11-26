<?php

namespace App\Jobs;

use App\Models\SupportMessage;
use App\Services\ChatPushTranslator;
use App\Services\FcmService;
use App\Services\SupportService;
use App\Support\ChatTranslationBroadcast;
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

    public function handle(SupportService $support, FcmService $fcm, ?ChatPushTranslator $text = null): void
    {
        $text ??= app(ChatPushTranslator::class);

        $message = SupportMessage::with('conversation.user', 'user')->find($this->messageId);
        if (! $message || ! $message->conversation || $message->sender_role === 'system') {
            return;
        }

        $fromCustomer = $message->sender_role === 'customer';
        $recipientIds = $fromCustomer
            ? $support->adminRecipientIds()
            : collect(array_filter([$message->conversation->user_id]));

        // ทีมงานอ่านไทย ลูกค้าต่างชาติได้คำตอบเป็นภาษาของเขา (ชุดที่ไม่ต้องแปลส่งก่อน)
        foreach ($text->group($message, $recipientIds, null) as $group) {
            $translated = $group['translate'] ? $text->translatedBody($message, $group['lang']) : null;
            if ($translated !== null) {
                ChatTranslationBroadcast::send($message);
            }

            [$title, $body] = $text->inLanguage($group['lang'], function () use ($message, $fromCustomer, $text, $translated) {
                $preview = $text->preview($message, $translated, 100);

                if ($fromCustomer) {
                    $customer = $message->conversation->user;
                    $senderName = $customer?->nickname ?: ($customer?->name ?? __('ลูกค้า'));

                    return [__('💬 ข้อความช่วยเหลือใหม่'), "$senderName: $preview"];
                }

                $senderName = $message->user?->nickname ?: ($message->user?->name ?? __('ทีมงาน'));

                return [__('💬 ทีมงานลุยเลเขา'), "$senderName: $preview"];
            });

            foreach ($group['ids'] as $userId) {
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
}
