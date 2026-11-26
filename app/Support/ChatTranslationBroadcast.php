<?php

namespace App\Support;

use App\Events\ChatMessageTranslated;
use App\Events\ChatPrivateSignal;
use App\Events\SupportMessageTranslated;
use App\Models\ChatMessage;
use App\Models\ChatPrivateMessage;
use App\Models\SupportMessage;
use App\Services\ChatTranslationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * ส่งคำแปลล่าสุดทุกภาษาของข้อความหนึ่งเข้าช่องของแชทนั้น (ใช้ร่วมกันสองงานแปล)
 * payload เหมือนกันทุกแบบ: {message_id, body, translations}
 */
final class ChatTranslationBroadcast
{
    public static function send(Model $message): void
    {
        $message->load('translations');
        $payload = app(ChatTranslationService::class)->presentTranslations($message);

        if ($payload === []) {
            return;
        }

        $body = trim((string) $message->getAttribute('body'));

        try {
            match (true) {
                $message instanceof ChatMessage => broadcast(new ChatMessageTranslated(
                    (int) $message->schedule_id,
                    (int) $message->id,
                    $body,
                    $payload,
                )),
                $message instanceof ChatPrivateMessage => self::sendPrivate($message, $body, $payload),
                $message instanceof SupportMessage => broadcast(new SupportMessageTranslated(
                    (int) $message->conversation_id,
                    (int) $message->id,
                    $body,
                    $payload,
                )),
                default => null,
            };
        } catch (\Throwable $e) {
            // Reverb ไม่ตอบ — คำแปลอยู่ในตารางแล้ว แอปได้จาก payload รอบถัดไป
            Log::warning('Chat translation broadcast failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, array{text: string, source_lang: ?string}>  $payload */
    private static function sendPrivate(ChatPrivateMessage $message, string $body, array $payload): void
    {
        $thread = $message->thread;
        if (! $thread) {
            return;
        }

        broadcast(new ChatPrivateSignal(
            (int) $thread->schedule_id,
            (int) $thread->user_id,
            ChatPrivateSignal::TRANSLATED,
            [
                'message_id' => (int) $message->id,
                'body' => $body,
                'translations' => (object) $payload,
            ],
        ));
    }
}
