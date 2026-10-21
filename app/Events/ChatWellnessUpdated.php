<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * เช็คอาการเปลี่ยน — ส่งเฉพาะส่วนสาธารณะ (จำนวนที่ตอบแล้ว) ไม่มีคำตอบของใคร
 * แอปของแต่ละคนดึงคำตอบตัวเอง/ทีมงานดึงรายชื่อผ่าน GET
 */
class ChatWellnessUpdated implements ShouldBroadcast
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
        return 'chat.wellness';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'wellness' => $this->payload,
        ];
    }
}
