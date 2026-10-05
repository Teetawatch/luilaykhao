<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * จำนวนคำขอแวะห้องน้ำที่ค้างอยู่เปลี่ยน — ส่งแค่ตัวเลข ไม่มีทางรู้ว่าใครขอ
 * (แอปโชว์แถบนี้เฉพาะทีมงาน)
 */
class ChatStopRequestsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{pending: int, urgent: int}  $summary
     */
    public function __construct(
        public int $scheduleId,
        public array $summary,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.stop_requests';
    }

    public function broadcastWith(): array
    {
        return ['schedule_id' => $this->scheduleId, ...$this->summary];
    }
}
