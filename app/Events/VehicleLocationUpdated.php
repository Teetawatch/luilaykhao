<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VehicleLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $vehicleId,
        public float $latitude,
        public float $longitude,
        public ?float $speed,
        public ?float $heading,
        public string $vehicleName,
        public string $licensePlate,
        public string $recordedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('vehicle-tracking'),
            new Channel("vehicle-tracking.{$this->vehicleId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed' => $this->speed,
            'heading' => $this->heading,
            'vehicle_name' => $this->vehicleName,
            'license_plate' => $this->licensePlate,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
