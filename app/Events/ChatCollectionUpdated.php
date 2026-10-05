<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * การเก็บเงินหน้างานเปลี่ยน (มีคนแจ้งโอน / สตาฟติ๊กจ่าย / ปิดยอด) — ส่งทั้งก้อนให้ client ทับการ์ด
 */
class ChatCollectionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $collection
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public array $collection,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.collection';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'collection' => $this->collection,
        ];
    }
}
