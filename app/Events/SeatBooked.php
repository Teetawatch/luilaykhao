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
        ];
    }
}
