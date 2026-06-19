<?php

namespace App\Http\Requests\Booking;

use App\Models\TripSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            // จุดรับที่ลูกค้าปักหมุดเอง — lat/lng/label ต้องมาพร้อมกันถ้าจะใช้
            'custom_pickup_label' => ['nullable', 'string', 'max:255', 'required_with:custom_pickup_lat'],
            'custom_pickup_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:custom_pickup_lng'],
            'custom_pickup_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:custom_pickup_lat'],
            'custom_pickup_note' => ['nullable', 'string', 'max:1000'],
            'seat_ids' => ['nullable', 'array'],
            'seat_ids.*' => ['string', 'max:10'],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.title' => ['required', 'string', 'max:50'],
            'passengers.*.name' => ['required', 'string', 'max:255'],
            'passengers.*.nickname' => ['required', 'string', 'max:100'],
            'passengers.*.id_card' => ['required', 'digits:13'],
            // Temporarily optional: the production mobile app does not send
            // birth_date yet. Revert to 'required' once the app ships.
            'passengers.*.birth_date' => ['nullable', 'date', 'before:today'],
            'passengers.*.phone' => ['required', 'digits:10'],
            'passengers.*.email' => ['nullable', 'email', 'max:255'],
            'passengers.0.email' => ['required_if:booking_for,friend', 'nullable', 'email', 'max:255'],
            'passengers.*.blood_group' => ['required', 'in:A,B,O,AB'],
            'passengers.*.allergies' => ['nullable', 'string', 'max:1000'],
            'passengers.*.halal_food' => ['required', 'boolean'],
            'passengers.*.health_notes' => ['nullable', 'string'],
            'passengers.*.emergency_contact' => ['required', 'string', 'max:255'],
            'passengers.*.emergency_phone' => ['required', 'digits:10'],
            'passengers.*.pickup_point_id' => ['nullable', 'integer', 'exists:schedule_pickup_points,id'],
            'passengers.*.dive_cert_level' => ['nullable', 'string'],
            'passengers.*.cert_number' => ['nullable', 'string'],
            'passengers.*.weight' => ['nullable', 'numeric', 'min:0'],
            'is_group' => ['nullable', 'boolean'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'group_notes' => ['nullable', 'string', 'max:1000'],
            'promotion_code' => ['nullable', 'string', 'max:50'],
            'is_join_trip' => ['nullable', 'boolean'],
            'booking_for' => ['nullable', 'in:self,friend'],
            'selected_addons' => ['nullable', 'array'],
            'selected_addons.*' => ['integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'passengers.*.birth_date.required' => 'กรุณาระบุวัน/เดือน/ปีเกิดของผู้เดินทาง',
            'passengers.*.birth_date.before' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
            'custom_pickup_label.required_with' => 'กรุณาระบุชื่อจุดรับที่ปักหมุด',
            'custom_pickup_lat.required_with' => 'กรุณาปักหมุดตำแหน่งจุดรับบนแผนที่',
            'custom_pickup_lng.required_with' => 'กรุณาปักหมุดตำแหน่งจุดรับบนแผนที่',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $scheduleId = $this->input('schedule_id');
            if (! $scheduleId) {
                return;
            }

            $schedule = TripSchedule::with('trip')->find($scheduleId);
            if (! $schedule || ! $schedule->trip) {
                return;
            }

            if ($schedule->trip->is_women_only) {
                $passengers = $this->input('passengers', []);
                $allowedTitles = ['นาง', 'นางสาว', 'น.ส.', 'นส', 'ด.ญ.']; // Added ด.ญ. just in case, but user said Mrs/Ms only. Let's stick to Mrs/Ms for now if unsure.
                // Re-reading: "ดูจากคำนำหน้า นาง และนางสาวเท่านั้น ผู้ชายจะจองไม่ได้"
                $allowedTitles = ['นาง', 'นางสาว'];

                foreach ($passengers as $index => $passenger) {
                    $title = $passenger['title'] ?? '';
                    if (! in_array($title, $allowedTitles)) {
                        $validator->errors()->add(
                            "passengers.{$index}.title",
                            "ทริปนี้เป็นทริปสำหรับผู้หญิงเท่านั้น กรุณาเลือกคำนำหน้าชื่อเป็น 'นาง' หรือ 'นางสาว'"
                        );
                    }
                }
            }
        });
    }
}
