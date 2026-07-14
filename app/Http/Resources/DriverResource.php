<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
                'license_plate' => $v->license_plate,
            ])),
        ];
    }
}
