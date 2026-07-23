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

    /**
     * รายการเสริมส่งได้ทั้งแบบเก่า (index เปล่า ๆ จากเว็บ/LIFF) และแบบใหม่ที่มี
     * จำนวนต่อรายการ — แปลงให้เป็นรูป {index, quantity} รูปเดียวก่อนตรวจ
     */
    protected function prepareForValidation(): void
    {
        $addons = $this->input('selected_addons');
        if (! is_array($addons)) {
            return;
        }

        $this->merge([
            'selected_addons' => array_values(array_map(function ($addon) {
                if (is_array($addon)) {
                    return [
                        'index' => isset($addon['index']) && is_numeric($addon['index'])
                            ? (int) $addon['index']
                            : null,
                        'quantity' => isset($addon['quantity']) && is_numeric($addon['quantity'])
                            ? (int) $addon['quantity']
                            : null,
                    ];
                }

                return [
                    'index' => is_numeric($addon) ? (int) $addon : null,
                    'quantity' => null,
                ];
            }, $addons)),
        ]);
    }

    public function rules(): array
    {
        // ซื้อเป็นของขวัญ: ผู้ให้รู้แค่ชื่อผู้รับ — ข้อมูลผู้เดินทางที่เหลือ
        // จะถูกเติมจากโปรไฟล์ผู้รับตอนกดรับของขวัญ (GiftService::claim)
        $passengerRequired = $this->boolean('is_gift') ? 'nullable' : 'required';

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
            'passengers.*.title' => [$passengerRequired, 'string', 'max:50'],
            'passengers.*.name' => ['required', 'string', 'max:255'],
            'passengers.*.nickname' => [$passengerRequired, 'string', 'max:100'],
            'passengers.*.id_card' => [$passengerRequired, 'digits:13'],
            // Temporarily optional: the production mobile app does not send
            // birth_date yet. Revert to 'required' once the app ships.
            'passengers.*.birth_date' => ['nullable', 'date', 'before:today'],
            'passengers.*.phone' => [$passengerRequired, 'digits:10'],
            'passengers.*.email' => ['nullable', 'email', 'max:255'],
            'passengers.0.email' => ['required_if:booking_for,friend', 'nullable', 'email', 'max:255'],
            'passengers.*.blood_group' => [$passengerRequired, 'in:A,B,O,AB'],
            'passengers.*.allergies' => ['nullable', 'string', 'max:1000'],
            'passengers.*.halal_food' => [$passengerRequired, 'boolean'],
            'passengers.*.health_notes' => ['nullable', 'string'],
            'passengers.*.emergency_contact' => [$passengerRequired, 'string', 'max:255'],
            'passengers.*.emergency_phone' => [$passengerRequired, 'digits:10'],
            'passengers.*.pickup_point_id' => ['nullable', 'integer', 'exists:schedule_pickup_points,id'],
            'passengers.*.dive_cert_level' => ['nullable', 'string'],
            'passengers.*.cert_number' => ['nullable', 'string'],
            'passengers.*.weight' => ['nullable', 'numeric', 'min:0'],
            'is_group' => ['nullable', 'boolean'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'group_notes' => ['nullable', 'string', 'max:1000'],
            'promotion_code' => ['nullable', 'string', 'max:50'],
            'is_join_trip' => ['nullable', 'boolean'],
            'is_gift' => ['nullable', 'boolean'],
            'gift_from_name' => ['nullable', 'string', 'max:100', 'required_if:is_gift,true'],
            'gift_message' => ['nullable', 'string', 'max:500'],
            'booking_for' => ['nullable', 'in:self,friend'],
            'selected_addons' => ['nullable', 'array'],
            'selected_addons.*.index' => ['required', 'integer', 'min:0'],
            'selected_addons.*.quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'selected_rentals' => ['nullable', 'array'],
            'selected_rentals.*.index' => ['required', 'integer', 'min:0'],
            'selected_rentals.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
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

            // บังคับระบุจุดรับสำหรับการจองปกติ เมื่อรอบมีจุดขึ้นรถตั้งไว้ — กันทุกช่องทาง
            // ที่ยิงเข้ามาไม่ให้เกิดการจองที่ไม่มีจุดรับ (join trip ยกเว้น; รอบที่ไม่มีจุด
            // ตั้งไว้ก็ยกเว้นฝั่ง backend เพราะบางช่องทาง เช่น LIFF ไม่มีปุ่มปักหมุดเอง)
            if (! $this->boolean('is_join_trip') && $schedule->pickupPoints()->exists()) {
                $hasBookingPickup = filled($this->input('pickup_point_id'));
                $hasPassengerPickup = collect($this->input('passengers', []))
                    ->contains(fn ($p) => filled($p['pickup_point_id'] ?? null));
                $hasCustomPin = filled($this->input('custom_pickup_lat'))
                    && filled($this->input('custom_pickup_lng'));

                if (! $hasBookingPickup && ! $hasPassengerPickup && ! $hasCustomPin) {
                    $validator->errors()->add(
                        'pickup_point_id',
                        'กรุณาเลือกจุดรับผู้โดยสาร หรือปักหมุดจุดรับเอง'
                    );
                }
            }

            if ($schedule->trip->is_women_only) {
                $passengers = $this->input('passengers', []);
                $allowedTitles = ['นาง', 'นางสาว', 'น.ส.', 'นส', 'ด.ญ.']; // Added ด.ญ. just in case, but user said Mrs/Ms only. Let's stick to Mrs/Ms for now if unsure.
                // Re-reading: "ดูจากคำนำหน้า นาง และนางสาวเท่านั้น ผู้ชายจะจองไม่ได้"
                $allowedTitles = ['นาง', 'นางสาว'];

                foreach ($passengers as $index => $passenger) {
                    $title = $passenger['title'] ?? '';

                    // ของขวัญ: ผู้ให้ไม่รู้คำนำหน้าผู้รับ — ตรวจซ้ำอีกทีตอนกดรับ (GiftService)
                    if ($this->boolean('is_gift') && $title === '') {
                        continue;
                    }

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
