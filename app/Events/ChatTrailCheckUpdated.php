<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * เช็คพอยต์เปลี่ยน (มีคนถึงจุด / ขอพัก / ปิดการ์ด) — ส่งทั้งก้อนให้ client ทับการ์ดเดิม
 * (ไม่มีชื่อคนที่ขอพัก — ทีมงานดึงผ่าน GET)
 */
class ChatTrailCheckUpdated implements ShouldBroadcast
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
        return 'chat.trail';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'trail_check' => $this->payload,
        ];
    }
}
