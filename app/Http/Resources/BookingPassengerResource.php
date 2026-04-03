<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingPassengerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'health_notes' => $this->health_notes,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'dive_cert_level' => $this->dive_cert_level,
            'cert_number' => $this->cert_number,
            'weight' => $this->weight,
        ];
    }
}
