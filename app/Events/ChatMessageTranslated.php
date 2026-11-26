<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * คำแปลอัตโนมัติของข้อความหนึ่งพร้อมแล้ว — แอปของแต่ละคนหยิบภาษาของตัวเอง
 * มาแทนข้อความในบับเบิล ส่ง body ที่แปลไปด้วย ให้แอปทิ้งคำแปลที่มาถึงช้า
 * กว่าการแก้ข้อความ (body ไม่ตรงแล้ว)
 *
 * Now ไม่ใช่ queued — ถูกยิงจากงานแปลในคิวอยู่แล้ว ไม่ต้องต่อคิวอีกรอบ
 */
class ChatMessageTranslated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, array{text: string, source_lang: ?string}>  $translations
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public string $body,
        public array $translations,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.translated';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'body' => $this->body,
            'translations' => (object) $this->translations,
        ];
    }
}
