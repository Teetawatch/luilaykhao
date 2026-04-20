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

        public string $licensePlate,
        public string $type,
        public string $recordedAt,
        public ?string $driverName = null,
        public ?string $driverPhone = null,
        public ?float $destLat = null,
        public ?float $destLng = null,
        public ?string $tripTitle = null,
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
            'type' => $this->type,
            'recorded_at' => $this->recordedAt,
            'driver_name' => $this->driverName,
            'driver_phone' => $this->driverPhone,
            'dest_lat' => $this->destLat,
            'dest_lng' => $this->destLng,
            'trip_title' => $this->tripTitle,
        ];
    }
}
