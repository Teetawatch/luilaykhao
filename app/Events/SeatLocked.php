<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeatLocked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $scheduleId,
        public string $seatId,
        public string $lockedUntil,
        public int $availableCount,
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
            'locked_until' => $this->lockedUntil,
            'available_count' => $this->availableCount,
        ];
    }
}
