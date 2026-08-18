<?php

namespace App\Http\Resources;

use App\Models\TripSchedule;
use App\Support\Countries;
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
                    // effective_price already reflects an active flash sale, so the
                    // "from ฿…" price on cards drops during a sale.
                    $prices->push((float) $s->effective_price);
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
            // ปลายทาง — ทริปเก่าทุกทริปเป็น 'domestic' โดยค่าเริ่มต้น
            'destination_type' => $this->destination_type,
            'is_international' => $this->isInternational(),
            'country_code' => $this->country_code,
            'country_label' => $this->countryLabel(),
            'destination_timezone' => $this->destinationTimezone(),
            // ต่างจากไทยกี่นาที (บวก = ปลายทางเร็วกว่า) — แอปไม่มีฐานข้อมูล
            // เขตเวลา IANA ติดมาด้วย (ไม่ได้ลง package timezone) จะคำนวณจากชื่อ
            // เขตเวลาเองไม่ได้ ส่งตัวเลขไปให้เลยดีกว่า แล้วแอปแค่บวกลบ
            'destination_offset_minutes' => $this->destinationOffsetMinutes(),
            // วีซ่า + เบอร์ฉุกเฉินท้องถิ่นของปลายทาง (null/ว่างสำหรับทริปในประเทศ)
            // — "ต้องขอวีซ่าไหม" เป็นคำถามแรกที่ลูกค้าถามก่อนตัดสินใจจอง และ 191
            // ของไทยใช้ที่ต่างประเทศไม่ได้
            'visa' => $this->isInternational() ? Countries::visa($this->country_code) : null,
            'emergency_numbers' => $this->isInternational()
                ? Countries::emergency($this->country_code)
                : [],
            'description' => $this->description,
            'difficulty' => $this->difficulty,
            'duration_days' => $this->duration_days,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'elevation_gain_m' => $this->elevation_gain_m,
            // เส้นทางจริงจาก GPX (ลดรูปแล้ว) — ใช้วาดกราฟความชันบนหน้าทริป
            'route_track' => $this->route_track,
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
            'videos' => $this->videos ?? [],
            'photos' => TripPhotoResource::collection($this->whenLoaded('photos')),
            'inclusions' => $this->inclusions ?? [],
            'exclusions' => $this->exclusions ?? [],
            'highlights' => $this->highlights ?? [],
            'is_featured' => (bool) $this->is_featured,
            'views_count' => (int) $this->views_count,
            'is_women_only' => (bool) $this->is_women_only,
            'must_know' => $this->must_know ?? null,
            // ทริปต่างประเทศใช้นโยบายยกเลิกคนละชุด — ตั๋วเครื่องบินคืนเงินไม่ได้
            // และเปลี่ยนชื่อบนตั๋วไม่ได้ ต่างจากทริปในประเทศที่เลื่อนรอบได้จริง
            // ส่งมาที่คีย์เดิม เพื่อให้ทุกหน้าจอที่วาดนโยบายอยู่แล้วถูกต้องทันที
            // โดยไม่ต้องรอปล่อยแอปรุ่นใหม่
            'cancellation_policy' => $this->isInternational()
                ? config('payment.cancellation_policy_international')
                : config('payment.cancellation_policy'),
            'itinerary' => $this->itinerary ?? [],
            'preparations' => $this->preparations ?? [],
            'faqs' => $this->faqs ?? [],
            'rental_items' => $this->rental_items ?? [],
            'rating' => $this->reviews()->where('is_approved', true)->avg('rating') ?: 0,
            'review_count' => $this->reviews()->where('is_approved', true)->count(),
            'rating_breakdown' => $this->ratingBreakdown(),
            'confirmed_passengers_count' => $this->confirmed_passengers_count,
            'bookings_count' => $this->bookings_count,
            'booked_passengers_count' => $this->booked_passengers_count,
            'seats_left' => $seatsLeft,
            'is_almost_full' => $seatsLeft !== null && $seatsLeft <= 5,
            'almost_full_date' => $this->lowestOpenSeatsDate(),
            'is_flash_sale' => $this->activeFlashSaleSchedule() !== null,
            'flash_sale_ends_at' => $this->activeFlashSaleSchedule()?->flash_sale_ends_at?->toISOString(),
            'schedules' => TripScheduleResource::collection($this->whenLoaded('schedules')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * The loaded schedule with a live flash sale that ends soonest — drives the
     * trip-card ⚡ badge and countdown. Null when schedules aren't loaded or none
     * is on flash sale.
     */
    private function activeFlashSaleSchedule(): ?TripSchedule
    {
        if (! $this->relationLoaded('schedules')) {
            return null;
        }

        return $this->schedules
            ->filter(fn ($s) => $s->flashSaleActive())
            ->sortBy(fn ($s) => $s->flash_sale_ends_at ?? '9999-12-31')
            ->first();
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
     * Departure date (Y-m-d) of the OPEN, upcoming schedule with the fewest
     * available seats — the round driving the "almost full" cue. Null when the
     * schedules relation isn't loaded or no eligible round exists.
     */
    private function lowestOpenSeatsDate(): ?string
    {
        if (! $this->relationLoaded('schedules')) {
            return null;
        }

        $today = now()->startOfDay();
        $schedule = $this->schedules
            ->filter(
                fn ($s) => $s->status === 'open'
                    && $s->available_seats > 0
                    && $s->departure_date
                    && $s->departure_date->gte($today)
            )
            ->sortBy(fn ($s) => $s->available_seats)
            ->first();

        return $schedule?->departure_date?->toDateString();
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
