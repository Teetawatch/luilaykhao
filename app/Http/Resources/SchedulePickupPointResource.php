<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchedulePickupPointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'region' => $this->region,
            'region_label' => $this->region_label,
            'pickup_location' => $this->pickup_location,
            'price' => (float) $this->price,
            'map_url' => $this->map_url,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
        ];
    }
}
