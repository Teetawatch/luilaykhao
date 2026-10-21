<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ตัวนับ "รับทราบ" ของประกาศหนึ่งเปลี่ยน (มีคนกด / ทีมงานเพิ่งขอ / ยกเลิกการขอ)
 * ack = null คือยกเลิกการขอแล้ว · ไม่มี acked_by_me เพราะเป็นของรวมทั้งห้อง
 */
class ChatAckUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $ack
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public ?array $ack,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.ack';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'ack' => $this->ack,
        ];
    }
}
