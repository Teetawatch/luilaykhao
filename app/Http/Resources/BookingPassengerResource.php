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
            'title' => $this->title,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'id_card' => $this->id_card,
            'phone' => $this->phone,
            'email' => $this->email,
            'blood_group' => $this->blood_group,
            'allergies' => $this->allergies,
            'health_notes' => $this->health_notes,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'dive_cert_level' => $this->dive_cert_level,
            'cert_number' => $this->cert_number,
            'weight' => $this->weight,
            'halal_food' => $this->halal_food,
            'pickup_point_id' => $this->pickup_point_id,
            'pickup_point' => $this->when($this->relationLoaded('pickupPoint') && $this->pickupPoint, function () {
                return [
                    'id' => $this->pickupPoint->id,
                    'region' => $this->pickupPoint->region,
                    'region_label' => $this->pickupPoint->region_label,
                    'pickup_location' => $this->pickupPoint->pickup_location,
                    'map_url' => $this->pickupPoint->map_url,
                    'notes' => $this->pickupPoint->notes,
                    'price' => $this->pickupPoint->price,
                ];
            }),
        ];
    }
}
