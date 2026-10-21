<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// The room's pinned announcement changed (pinned a message, or unpinned → null).
class ChatPinned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $message  หมุดล่าสุด (null = ไม่มีหมุดเหลือ) — ไคลเอนต์รุ่นเก่า
     * @param  array<int, array<string, mixed>>  $pinned  หมุดทั้งหมด ปักล่าสุดก่อน
     */
    public function __construct(
        public int $scheduleId,
        public ?array $message,
        public array $pinned = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.pinned';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message' => $this->message,
            'pinned' => $this->pinned,
        ];
    }
}
