<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ความเคลื่อนไหวของเธรดถามทีมงานแบบส่วนตัว — ข้อความใหม่ / ถูกลบ / อ่านแล้ว
 *
 * ส่งสองช่องพร้อมกัน: ช่องของเธรด (ลูกทริปเจ้าของฟังอยู่) และช่องรวมของทีมงาน
 * รอบนั้น (กล่องข้อความของทีมงานฟังทุกเธรดจากช่องเดียว) ไม่เคยส่งเข้าช่องห้องรวม
 */
class ChatPrivateSignal implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const MESSAGE = 'chat.private.message';

    public const UPDATED = 'chat.private.updated';

    public const READ = 'chat.private.read';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $scheduleId,
        public int $threadUserId,
        public string $name,
        public array $payload,
    ) {}

    public static function threadChannel(int $scheduleId, int $userId): string
    {
        return "chat.private.{$scheduleId}.{$userId}";
    }

    public static function staffChannel(int $scheduleId): string
    {
        return "chat.private-staff.{$scheduleId}";
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(self::threadChannel($this->scheduleId, $this->threadUserId)),
            new PrivateChannel(self::staffChannel($this->scheduleId)),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'thread_user_id' => $this->threadUserId,
            ...$this->payload,
        ];
    }
}
