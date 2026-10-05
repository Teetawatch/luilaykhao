<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ของหายเปลี่ยนสถานะ (มีเจ้าของ / คืนแล้ว) — ส่งเฉพาะส่วนสาธารณะ ไม่มีชื่อ/เบอร์เจ้าของ
 */
class ChatLostItemUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $item
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public array $item,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.lost_item';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'lost_item' => $this->item,
        ];
    }
}
