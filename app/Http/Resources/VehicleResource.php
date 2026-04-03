<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'seat_layout' => $this->seat_layout,
            'license_plate' => $this->license_plate,
            'color' => $this->color,
            'driver_name' => $this->driver_name,
            'driver_phone' => $this->driver_phone,
            'images' => $this->images ?? [],
            'pickup_points' => $this->whenLoaded('pickupPoints', fn() =>
                $this->pickupPoints->map(fn($p) => [
                    'id' => $p->id,
                    'region' => $p->region,
                    'region_label' => $p->region_label,
                    'pickup_location' => $p->pickup_location,
                    'map_url' => $p->map_url,
                    'latitude' => $p->latitude,
                    'longitude' => $p->longitude,
                    'notes' => $p->notes,
                    'sort_order' => $p->sort_order,
                ])
            ),
            'schedules_count' => $this->whenCounted('schedules'),
        ];
    }
}
