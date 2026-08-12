<?php

namespace App\Http\Requests\Admin;

use App\Rules\KnownCountry;
use Illuminate\Foundation\Http\FormRequest;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'exists:categories,slug'],
            'location' => ['required', 'string', 'max:255'],
            // ภาคเป็นของประเทศไทย — ทริปต่างประเทศระบุประเทศแทน
            'region' => ['required_if:destination_type,domestic', 'nullable', 'string', 'max:50'],
            'destination_type' => ['nullable', 'in:domestic,international'],
            'country_code' => [
                'required_if:destination_type,international',
                'nullable',
                'string',
                'size:2',
                new KnownCountry,
            ],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'description' => ['nullable', 'string'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'elevation_gain_m' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'max_participants' => ['required', 'integer', 'min:1'],
            'price_per_person' => ['required', 'numeric', 'min:0'],
            'departure_point' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['nullable', 'in:active,inactive,full'],
            'cover_image' => ['nullable', 'string'],
            'thumbnail_image' => ['nullable', 'string'],
            'is_featured' => ['nullable', 'boolean'],
            'is_women_only' => ['nullable', 'boolean'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['string'],
            'videos' => ['nullable', 'array'],
            'videos.*' => ['string'],
            'inclusions' => ['nullable', 'array'],
            'inclusions.*' => ['string'],
            'exclusions' => ['nullable', 'array'],
            'exclusions.*' => ['string'],
            'highlights' => ['nullable', 'array'],
            'highlights.*.title' => ['required_with:highlights', 'string', 'max:255'],
            'highlights.*.desc' => ['required_with:highlights', 'string', 'max:500'],
            'highlights.*.icon' => ['required_with:highlights', 'string', 'max:100'],
            'must_know' => ['nullable', 'array'],
            'must_know.items' => ['nullable', 'array'],
            'must_know.items.*.name' => ['required_with:must_know.items', 'string', 'max:255'],
            'must_know.items.*.price' => ['required_with:must_know.items', 'numeric', 'min:0'],
            'must_know.items.*.price_type' => ['nullable', 'in:per_booking,per_person'],
            'must_know.items.*.image_url' => ['nullable', 'string', 'max:2048'],
            'must_know.remarks' => ['nullable', 'string', 'max:1000'],
            'itinerary' => ['nullable', 'array'],
            'itinerary.*.sector' => ['nullable', 'string', 'max:255'],
            'itinerary.*.items' => ['required_with:itinerary', 'array'],
            'itinerary.*.items.*.day' => ['required', 'integer'],
            'itinerary.*.items.*.title' => ['required', 'string', 'max:255'],
            'itinerary.*.items.*.description' => ['required', 'string'],
            'preparations' => ['nullable', 'array'],
            'preparations.*' => ['string'],
            'faqs' => ['nullable', 'array'],
            'faqs.*.question' => ['required_with:faqs', 'string', 'max:255'],
            'faqs.*.answer' => ['required_with:faqs', 'string', 'max:2000'],
            'rental_items' => ['nullable', 'array'],
            'rental_items.*.name' => ['required_with:rental_items', 'string', 'max:255'],
            'rental_items.*.price' => ['required_with:rental_items', 'numeric', 'min:0'],
            'rental_items.*.image_url' => ['nullable', 'string', 'max:2048'],
            'rental_items.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
