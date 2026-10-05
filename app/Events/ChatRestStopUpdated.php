<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * จุดพักเปลี่ยน (มีคนขึ้นรถ / ขยายเวลา / ออกรถ) — ส่งทั้งก้อนให้ client ทับการ์ดเดิม
 */
class ChatRestStopUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $stop
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public array $stop,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.rest_stop';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'rest_stop' => $this->stop,
        ];
    }
}
