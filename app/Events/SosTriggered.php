<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SosTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public int $sosId,
        public int $scheduleId,
        public string $userName,
        public ?string $message,
        public ?string $contactPhone,
        public ?float $latitude,
        public ?float $longitude,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->recipientUserId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'sos_id' => $this->sosId,
            'schedule_id' => $this->scheduleId,
            'sos_user_name' => $this->userName,
            'contact_phone' => $this->contactPhone ?? '',
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'sos_message' => $this->message ?? '',
        ];
    }
}
