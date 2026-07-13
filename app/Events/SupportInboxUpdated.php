<?php

namespace App\Events;

use App\Models\SupportConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * มีความเคลื่อนไหวในห้องช่วยเหลือ — ส่งสรุปห้องให้หน้ากล่องข้อความของทีมงานอัปเดตแบบ realtime
 * โดยไม่ต้องรอ poll (badge ยังไม่อ่าน + ดันห้องขึ้นบนสุด)
 */
class SupportInboxUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportConversation $conversation,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.admins'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.inbox';
    }

    public function broadcastWith(): array
    {
        $conversation = $this->conversation->loadMissing('user:id,name,nickname,avatar');

        return [
            'id' => $conversation->id,
            'user' => [
                'id' => $conversation->user?->id,
                'name' => $conversation->user?->name,
                'nickname' => $conversation->user?->nickname,
                'avatar_url' => $conversation->user?->avatar_url,
            ],
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'last_message_preview' => $conversation->last_message_preview,
        ];
    }
}
