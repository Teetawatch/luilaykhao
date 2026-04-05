<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:van,boat'],
            'capacity' => ['required', 'integer', 'min:1'],
            'seat_layout' => ['nullable', 'array'],
            'license_plate' => ['nullable', 'string', 'max:20'],
            'color' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string'],
            'driver_photo' => ['nullable', 'string'],
            'interior_video' => ['nullable', 'string'],
        ];
    }
}
