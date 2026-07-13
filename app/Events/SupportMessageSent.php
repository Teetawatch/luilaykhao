<?php

namespace App\Events;

use App\Models\SupportMessage;
use App\Services\SupportService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ข้อความใหม่ในห้องช่วยเหลือ — ทั้งลูกค้าเจ้าของห้องและทีมงานที่กำลังเปิดเคสนี้อยู่ฟังได้
 */
class SupportMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("support.conversation.{$this->message->conversation_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.message';
    }

    public function broadcastWith(): array
    {
        $this->message->loadMissing('user');

        // currentUserId ไม่ทราบ — ผู้รับคำนวณ is_mine ฝั่ง client (ข้อความตัวเองเติมแบบ optimistic)
        return app(SupportService::class)->presentMessage($this->message);
    }
}
