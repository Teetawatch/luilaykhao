<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ผลโหวต/สถานะของโพลเปลี่ยน — ส่งทั้งก้อนไปให้ client ทับของเดิมบนการ์ดโพล
 * (ส่งแบบไม่ผูกผู้ใช้ ฝั่ง client คำนวณ "ข้อที่ฉันเลือก" จาก voter_ids เอง)
 */
class ChatPollUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $poll
     */
    public function __construct(
        public int $scheduleId,
        public int $messageId,
        public array $poll,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.schedule.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.poll';
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'message_id' => $this->messageId,
            'poll' => $this->poll,
        ];
    }
}
