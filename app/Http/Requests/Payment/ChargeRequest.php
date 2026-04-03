<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_ref' => ['required', 'string', 'exists:bookings,booking_ref'],
            'omise_token' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:1'],
        ];
    }
}
