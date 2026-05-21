<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_schedule_id' => ['required', 'integer', 'exists:trip_schedules,id'],
            'seat_ids' => ['nullable', 'array'],
            'seat_ids.*' => ['string', 'max:30'],
            'pickup_point_id' => ['nullable', 'integer', 'exists:schedule_pickup_points,id'],
        ];
    }
}
