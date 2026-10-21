<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ฝากของลูกหาบเปลี่ยน (ลงชื่อฝาก / ชั่ง / ส่งยอด / คืนกระเป๋า) — ส่งทั้งก้อนให้ client ทับการ์ด
 */
class ChatPorterUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.porter';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'porter' => $this->payload,
        ];
    }
}
