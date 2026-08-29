<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeatBooked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $scheduleId,
        public string $seatId,
        public int $availableCount,
        // คันที่ที่นั่งนี้อยู่ (null = รอบนี้มีรถคันเดียว) — หน้าจองที่เปิดผังของ
        // อีกคันอยู่ต้องมองข้ามอีเวนต์นี้ ไม่งั้นที่นั่งชื่อเดียวกันจะกะพริบตามกัน
        public ?int $vehicleOptionId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("schedule.{$this->scheduleId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'seat_id' => $this->seatId,
            'available_count' => $this->availableCount,
            'vehicle_option_id' => $this->vehicleOptionId,
        ];
    }
}
