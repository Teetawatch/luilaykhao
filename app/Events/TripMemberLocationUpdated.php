<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * เพื่อนร่วมทริปคนหนึ่งขยับ — หรือเพิ่งเลิกแชร์
 *
 * ช่องเป็น private โดยตั้งใจ (ต่างจาก vehicle-tracking ที่เป็น public): ตำแหน่งรถ
 * คือของบริษัท ส่วนตำแหน่งคนคือของคนคนนั้น ใครจะฟังได้ต้องผ่าน
 * [\App\Services\SosParticipantService] ก่อนเสมอ — ดู routes/channels.php
 */
class TripMemberLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $member
     */
    public function __construct(
        public int $scheduleId,
        public array $member,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("trip-members.{$this->scheduleId}")];
    }

    public function broadcastAs(): string
    {
        return 'member.location';
    }

    public function broadcastWith(): array
    {
        return ['member' => $this->member];
    }
}
