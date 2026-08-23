<?php

namespace App\Http\Requests\Admin;

use App\Models\TripSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

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
            'transport_type' => ['required', Rule::in(TripSchedule::TRANSPORT_TYPES)],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['nullable', 'in:open,closed,full,cancelled'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'deposit_enabled' => ['nullable', 'boolean'],
            'deposit_type' => ['nullable', 'in:amount,percent', 'required_if:deposit_enabled,true'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'required_if:deposit_type,amount'],
            'deposit_percent' => ['nullable', 'integer', 'min:1', 'max:99', 'required_if:deposit_type,percent'],
            'join_trip_enabled' => ['nullable', 'boolean'],
            'join_trip_price' => ['nullable', 'numeric', 'min:0'],
            'is_charter' => ['nullable', 'boolean'],
            'flash_sale_enabled' => ['nullable', 'boolean'],
            'flash_sale_price' => ['nullable', 'numeric', 'min:0', 'required_if:flash_sale_enabled,true'],
            'flash_sale_starts_at' => ['nullable', 'date'],
            'flash_sale_ends_at' => ['nullable', 'date', 'after:now', 'after:flash_sale_starts_at'],
            ...self::flightPlanRules(),
        ];
    }

    /**
     * จุดนัดพบที่สนามบิน + ขาบินของรอบที่บินไป
     *
     * แยกออกมาเป็น static เพราะหน้าแก้ไขรอบ (AdminController::updateSchedule)
     * ต้องรับฟิลด์ชุดเดียวกันเป๊ะ — ข้อมูลเที่ยวบินส่วนใหญ่ถูกกรอกทีหลัง หลังจาก
     * ออกตั๋วจริงแล้ว ไม่ใช่ตอนสร้างรอบ ถ้ากฎสองที่ไม่ตรงกันจะกลายเป็นว่าสร้างได้
     * แต่แก้ไม่ได้ (หรือกลับกัน)
     *
     * ทุกช่อง nullable — รอบเปิดขายได้ก่อนที่ไฟลต์จะคอนเฟิร์ม
     */
    public static function flightPlanRules(): array
    {
        return [
            'meeting_point' => ['nullable', 'string', 'max:255'],
            'meeting_map_url' => ['nullable', 'url', 'max:500'],
            'meeting_time' => ['nullable', 'date_format:H:i'],
            'baggage_allowance' => ['nullable', 'string', 'max:255'],
            'flights' => ['nullable', 'array', 'max:12'],
            'flights.*.direction' => ['required', Rule::in(['outbound', 'return'])],
            'flights.*.airline' => ['nullable', 'string', 'max:100'],
            'flights.*.flight_no' => ['nullable', 'string', 'max:20'],
            'flights.*.from' => ['nullable', 'string', 'max:100'],
            'flights.*.to' => ['nullable', 'string', 'max:100'],
            'flights.*.depart_at' => ['nullable', 'date'],
            'flights.*.arrive_at' => ['nullable', 'date'],
            'flights.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
