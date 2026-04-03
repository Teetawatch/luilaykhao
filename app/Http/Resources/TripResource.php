<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'type' => $this->type,
            'location' => $this->location,
            'description' => $this->description,
            'difficulty' => $this->difficulty,
            'duration_days' => $this->duration_days,
            'max_participants' => $this->max_participants,
            'price_per_person' => $this->price_per_person,
            'departure_point' => $this->departure_point,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'cover_image' => $this->cover_image,
            'is_featured' => (bool) $this->is_featured,
            'schedules' => TripScheduleResource::collection($this->whenLoaded('schedules')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
