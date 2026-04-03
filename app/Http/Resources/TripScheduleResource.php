<?php

namespace App\Http\Resources;

use App\Http\Resources\SchedulePickupPointResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'trip' => new TripResource($this->whenLoaded('trip')),
            'departure_date' => $this->departure_date?->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'total_seats' => $this->total_seats,
            'booked_seats' => $this->booked_seats,
            'available_seats' => $this->available_seats,
            'transport_type' => $this->transport_type,
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'status' => $this->status,
            'price' => $this->effective_price,
            'pickup_points' => SchedulePickupPointResource::collection($this->whenLoaded('pickupPoints')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
