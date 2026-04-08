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
            'gallery' => $this->gallery ?? [],
            'inclusions' => $this->inclusions ?? [],
            'exclusions' => $this->exclusions ?? [],
            'highlights' => $this->highlights ?? [],
            'is_featured' => (bool) $this->is_featured,
            'is_women_only' => (bool) $this->is_women_only,
            'must_know' => $this->must_know ?? null,
            'rating' => $this->reviews()->where('is_approved', true)->avg('rating') ?: 0,
            'review_count' => $this->reviews()->where('is_approved', true)->count(),
            'schedules' => TripScheduleResource::collection($this->whenLoaded('schedules')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
