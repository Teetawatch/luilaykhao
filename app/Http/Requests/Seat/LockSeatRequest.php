<?php

namespace App\Http\Requests\Seat;

use Illuminate\Foundation\Http\FormRequest;

class LockSeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seat_ids' => ['required', 'array', 'min:1', 'max:10'],
            'seat_ids.*' => ['required', 'string', 'max:10'],
            'pickup_point_id' => ['nullable', 'integer', 'exists:schedule_pickup_points,id'],
            'pickup_region' => ['nullable', 'string', 'max:100'],
        ];
    }
}
