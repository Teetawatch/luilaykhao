<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * จำนวนคำขอยา/ของที่ค้างเปลี่ยน — ส่งแค่ตัวเลข ("ใครขอยาอะไร" ไม่กระจายเข้าห้อง)
 * ทีมงานได้สัญญาณนี้แล้วดึงคิวเต็มผ่าน GET เอง
 */
class ChatSupplyRequestsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $scheduleId,
        public int $pending,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.supplies';
    }

    public function broadcastWith(): array
    {
        return ['schedule_id' => $this->scheduleId, 'pending' => $this->pending];
    }
}
