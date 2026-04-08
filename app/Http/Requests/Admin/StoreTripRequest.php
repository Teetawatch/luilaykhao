<?php

namespace App\Http\Requests\Admin;

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
            'type' => ['required', 'in:trekking,diving,snorkeling,climbing'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'max_participants' => ['required', 'integer', 'min:1'],
            'price_per_person' => ['required', 'numeric', 'min:0'],
            'departure_point' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['nullable', 'in:active,inactive,full'],
            'cover_image' => ['nullable', 'string'],
            'is_featured' => ['nullable', 'boolean'],
            'is_women_only' => ['nullable', 'boolean'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['string'],
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
            'must_know.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
