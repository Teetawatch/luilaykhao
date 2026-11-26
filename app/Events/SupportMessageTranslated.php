<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * คำแปลอัตโนมัติของข้อความในกล่องแชทซัพพอร์ตพร้อมแล้ว — ลูกค้าและแอดมินที่
 * เปิดเคสนี้อยู่หยิบภาษาของตัวเองไปแทนข้อความ (ดู ChatMessageTranslated)
 */
class SupportMessageTranslated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, array{text: string, source_lang: ?string}>  $translations
     */
    public function __construct(
        public int $conversationId,
        public int $messageId,
        public string $body,
        public array $translations,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("support.conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'support.message.translated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'body' => $this->body,
            'translations' => (object) $this->translations,
        ];
    }
}
