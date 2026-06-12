<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'exists:trips,id'],
            'departure_date' => ['required', 'date', 'after_or_equal:today'],
            'departs_at' => ['nullable', 'date', function (string $attribute, mixed $value, \Closure $fail) {
                $departureDate = $this->input('departure_date');
                if (! $departureDate) {
                    return;
                }

                $departsAt = Carbon::parse($value);
                $tripDate = Carbon::parse($departureDate);

                if ($departsAt->lt($tripDate->copy()->subDay()->startOfDay())
                    || $departsAt->gt($tripDate->copy()->endOfDay())) {
                    $fail('เวลาออกเดินทางต้องอยู่ระหว่าง 1 วันก่อนวันทริปถึงสิ้นสุดวันทริป');
                }
            }],
            'return_date' => ['required', 'date', 'after_or_equal:departure_date'],
            'total_seats' => ['required', 'integer', 'min:1'],
            'transport_type' => ['required', 'in:van,boat,bus'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['nullable', 'in:open,closed,full,cancelled'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'installment_enabled' => ['nullable', 'boolean'],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:6'],
            'installment_interval_days' => ['nullable', 'integer', 'min:1'],
            'deposit_enabled' => ['nullable', 'boolean'],
            'deposit_type' => ['nullable', 'in:amount,percent', 'required_if:deposit_enabled,true'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'required_if:deposit_type,amount'],
            'deposit_percent' => ['nullable', 'integer', 'min:1', 'max:99', 'required_if:deposit_type,percent'],
            'join_trip_enabled' => ['nullable', 'boolean'],
            'join_trip_price' => ['nullable', 'numeric', 'min:0'],
            'is_charter' => ['nullable', 'boolean'],
        ];
    }
}
