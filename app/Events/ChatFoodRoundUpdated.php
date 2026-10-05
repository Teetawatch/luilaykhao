<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ออเดอร์ในรอบสั่งอาหารเปลี่ยน (มีคนสั่ง/แก้/ลบ หรือสตาฟปิด-เปิดรอบ) — ส่งทั้งก้อน
 * ให้ client ทับของเดิมบนการ์ด
 */
class ChatFoodRoundUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $round
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public array $round,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.food';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'food_round' => $this->round,
        ];
    }
}
