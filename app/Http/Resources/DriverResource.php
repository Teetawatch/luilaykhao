<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'photo' => $this->photo,
            'license_number' => $this->license_number,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'vehicles_count' => $this->whenCounted('vehicles'),
            'vehicles' => $this->whenLoaded('vehicles', fn () => $this->vehicles->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'type' => $v->type,
                'capacity' => $v->capacity,
                'license_plate' => $v->license_plate,
                'color' => $v->color,
                'has_driver_pin' => $v->hasDriverPin(),
            ])),
            'last_trip_date' => $this->whenNotNull(
                $this->last_trip_date ? Carbon::parse($this->last_trip_date)->toDateString() : null,
            ),
            'upcoming_trips_count' => $this->when(
                isset($this->upcoming_trips_count),
                fn () => (int) $this->upcoming_trips_count,
            ),
        ];
    }
}
