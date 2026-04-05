<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_id' => ['required', 'exists:trip_schedules,id'],
            'pickup_region' => ['nullable', 'string', 'max:50'],
            'seat_ids' => ['nullable', 'array'],
            'seat_ids.*' => ['string', 'max:10'],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.name' => ['required', 'string', 'max:255'],
            'passengers.*.nickname' => ['nullable', 'string', 'max:100'],
            'passengers.*.id_card' => ['nullable', 'string', 'max:20'],
            'passengers.*.phone' => ['nullable', 'string', 'max:20'],
            'passengers.*.blood_group' => ['nullable', 'string', 'max:10'],
            'passengers.*.allergies' => ['nullable', 'string', 'max:1000'],
            'passengers.*.health_notes' => ['nullable', 'string'],
            'passengers.*.emergency_contact' => ['nullable', 'string', 'max:255'],
            'passengers.*.emergency_phone' => ['nullable', 'string', 'max:20'],
            'passengers.*.dive_cert_level' => ['nullable', 'string'],
            'passengers.*.cert_number' => ['nullable', 'string'],
            'passengers.*.weight' => ['nullable', 'numeric', 'min:0'],
            'is_group' => ['nullable', 'boolean'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'group_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
