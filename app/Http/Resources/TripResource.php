<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $prices = collect();
        if ($this->relationLoaded('schedules') && $this->schedules->isNotEmpty()) {
            $prices = $this->schedules->map(fn($s) => (float) ($s->price_override ?? $this->price_per_person));
        } else {
            $prices->push((float) $this->price_per_person);
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'type' => $this->type,
            'location' => $this->location,
            'region' => $this->region,
            'description' => $this->description,
            'difficulty' => $this->difficulty,
            'duration_days' => $this->duration_days,
            'max_participants' => $this->max_participants,
            'price_per_person' => $this->price_per_person,
            'min_price' => $prices->min(),
            'max_price' => $prices->max(),
            'departure_point' => $this->departure_point,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'cover_image' => $this->cover_image,
            'thumbnail_image' => $this->thumbnail_image,
            'gallery' => $this->gallery ?? [],
            'inclusions' => $this->inclusions ?? [],
            'exclusions' => $this->exclusions ?? [],
            'highlights' => $this->highlights ?? [],
            'is_featured' => (bool) $this->is_featured,
            'is_women_only' => (bool) $this->is_women_only,
            'must_know' => $this->must_know ?? null,
            'itinerary' => $this->itinerary ?? [],
            'preparations' => $this->preparations ?? [],
            'rating' => $this->reviews()->where('is_approved', true)->avg('rating') ?: 0,
            'review_count' => $this->reviews()->where('is_approved', true)->count(),
            'confirmed_passengers_count' => $this->confirmed_passengers_count,
            'schedules' => TripScheduleResource::collection($this->whenLoaded('schedules')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
