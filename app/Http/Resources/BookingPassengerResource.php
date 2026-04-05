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
            'nickname' => $this->nickname,
            'id_card' => $this->id_card,
            'phone' => $this->phone,
            'blood_group' => $this->blood_group,
            'allergies' => $this->allergies,
            'health_notes' => $this->health_notes,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'dive_cert_level' => $this->dive_cert_level,
            'cert_number' => $this->cert_number,
            'weight' => $this->weight,
        ];
    }
}
