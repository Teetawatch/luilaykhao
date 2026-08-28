<?php

namespace App\Http\Requests\Payment;

use App\Support\PaymentQuote;
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
            'payment_type' => ['nullable', 'in:full,installment,deposit,split'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'amount' => ['required', 'numeric', 'min:1'],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:'.PaymentQuote::MAX_INSTALLMENT_COUNT],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
        ];
    }
}
