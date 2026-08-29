<?php

namespace App\Http\Requests\Seat;

use App\Models\TripSchedule;
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
            'seat_ids' => ['required', 'array', 'min:1', 'max:'.$this->maxSeats()],
            'seat_ids.*' => ['required', 'string', 'max:10'],
            'pickup_point_id' => ['nullable', 'integer', 'exists:schedule_pickup_points,id'],
            'pickup_region' => ['nullable', 'string', 'max:100'],
            // คันที่ที่นั่งพวกนี้อยู่ — ความเป็นเจ้าของของรอบตรวจใน SeatController
            'vehicle_option_id' => ['nullable', 'integer', 'exists:schedule_vehicle_options,id'],
        ];
    }

    /**
     * Cap the number of seats by the schedule's total capacity so larger
     * vehicles (e.g. 11+ seats) can be locked in a single request.
     */
    private function maxSeats(): int
    {
        $scheduleId = $this->route('id');
        $totalSeats = TripSchedule::whereKey($scheduleId)->value('total_seats');

        return max(1, (int) $totalSeats);
    }
}
