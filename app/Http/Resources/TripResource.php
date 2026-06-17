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
            foreach ($this->schedules as $s) {
                if ($s->relationLoaded('pickupPoints') && $s->pickupPoints->isNotEmpty()) {
                    foreach ($s->pickupPoints as $pt) {
                        $prices->push((float) $pt->price);
                    }
                } else {
                    $prices->push((float) ($s->price_override ?? $this->price_per_person));
                }
            }
        }

        if ($prices->isEmpty()) {
            $prices->push((float) $this->price_per_person);
        }

        $seatsLeft = $this->lowestOpenSeats();

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
            'photos' => TripPhotoResource::collection($this->whenLoaded('photos')),
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
            'rating_breakdown' => $this->ratingBreakdown(),
            'confirmed_passengers_count' => $this->confirmed_passengers_count,
            'seats_left' => $seatsLeft,
            'is_almost_full' => $seatsLeft !== null && $seatsLeft <= 5,
            'schedules' => TripScheduleResource::collection($this->whenLoaded('schedules')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Lowest available-seat count across this trip's OPEN, upcoming schedules —
     * the basis for the "almost full / last seats" scarcity cue. Null when the
     * schedules relation isn't loaded or no open upcoming round exists.
     */
    private function lowestOpenSeats(): ?int
    {
        if (! $this->relationLoaded('schedules')) {
            return null;
        }

        $today = now()->startOfDay();
        $open = $this->schedules->filter(
            fn ($s) => $s->status === 'open'
                && $s->available_seats > 0
                && $s->departure_date
                && $s->departure_date->gte($today)
        );

        if ($open->isEmpty()) {
            return null;
        }

        return (int) $open->min(fn ($s) => $s->available_seats);
    }

    /**
     * Average sub-ratings (guide/vehicle/food/value) from approved reviews.
     * Returns null per category when no review has rated it.
     */
    private function ratingBreakdown(): array
    {
        $avg = $this->reviews()
            ->where('is_approved', true)
            ->selectRaw('AVG(rating_guide) as guide, AVG(rating_vehicle) as vehicle, AVG(rating_food) as food, AVG(rating_value) as value')
            ->first();

        $round = fn ($v) => $v === null ? null : round((float) $v, 1);

        return [
            'guide' => $round($avg?->guide),
            'vehicle' => $round($avg?->vehicle),
            'food' => $round($avg?->food),
            'value' => $round($avg?->value),
        ];
    }
}
