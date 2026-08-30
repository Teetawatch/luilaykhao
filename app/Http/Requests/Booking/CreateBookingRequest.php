<?php

namespace App\Http\Requests\Booking;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Support\Countries;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateBookingRequest extends FormRequest
{
    /** เกณฑ์อายุพาสปอร์ตคงเหลือขั้นต่ำที่สายการบินส่วนใหญ่ใช้ */
    private const PASSPORT_VALIDITY_MONTHS = 6;

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
        $this->normalisePassengerNationality();

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

    /**
     * เติมสัญชาติ 'TH' ให้ผู้โดยสารที่ไม่ได้ส่งมา
     *
     * ทุกช่องทางที่มีอยู่ก่อนหน้านี้ (แอป, LIFF, ลิงก์กรอกเอง) ไม่รู้จักฟิลด์นี้
     * การเติมค่าเริ่มต้นตรงนี้ทำให้กฎที่อ้าง `required_if:...nationality,TH`
     * ทำงานได้จริง แทนที่จะเงียบไปเพราะฟิลด์หายทั้งช่อง
     */
    private function normalisePassengerNationality(): void
    {
        $passengers = $this->input('passengers');
        if (! is_array($passengers)) {
            return;
        }

        $this->merge([
            'passengers' => array_map(function ($passenger) {
                if (! is_array($passenger)) {
                    return $passenger;
                }

                $nationality = $passenger['nationality'] ?? null;
                $passenger['nationality'] = filled($nationality)
                    ? strtoupper((string) $nationality)
                    : Countries::HOME;

                return $passenger;
            }, $passengers),
        ]);
    }

    /**
     * ทริปของรอบที่กำลังจอง — อ่านครั้งเดียวแล้วใช้ซ้ำ เพราะทั้ง rules() และ
     * withValidator() ต้องรู้ว่าทริปนี้ออกนอกประเทศไหม
     */
    private function trip(): ?Trip
    {
        if ($this->resolvedTrip !== false) {
            return $this->resolvedTrip;
        }

        $scheduleId = $this->input('schedule_id');
        $schedule = $scheduleId ? TripSchedule::with('trip')->find($scheduleId) : null;

        return $this->resolvedTrip = $schedule?->trip;
    }

    /** false = ยังไม่ได้หา, null = หาแล้วไม่เจอ */
    private Trip|null|false $resolvedTrip = false;

    private function isInternational(): bool
    {
        return (bool) $this->trip()?->isInternational();
    }

    /**
     * ผู้ส่งคำขอนี้รู้จักช่องเอกสารเดินทางไหม
     *
     * แอปที่ลูกค้าติดตั้งอยู่ก่อนรุ่นที่รองรับทริปต่างประเทศไม่มีช่องพวกนี้เลย
     * ถ้าบังคับกับทุกคำขอ ลูกค้ากลุ่มนั้นจะจองไม่ได้จนกว่าจะอัปเดตแอป ซึ่งกินเวลา
     * เป็นสัปดาห์และมีคนที่ไม่อัปเดตเลย จึงถือว่า "ไม่ส่งคีย์มาเลย" = จองผ่านช่องทาง
     * ที่ยังถามไม่ได้ ให้จองไปก่อนแล้วตามเก็บทีหลัง (PublicPassportController +
     * อีเมลตามเก็บ + หน้า /admin/passport-followup)
     *
     * ต่างจาก "ส่งคีย์มาแต่ปล่อยว่าง" ซึ่งแปลว่าเว็บ/LIFF/แอปรุ่นใหม่ถามแล้วแต่
     * ลูกค้าไม่กรอก — กรณีนั้นยังต้องเตือนที่หน้าจองเหมือนเดิม
     */
    private function clientKnowsPassportFields(): bool
    {
        foreach ($this->input('passengers', []) as $passenger) {
            if (! is_array($passenger)) {
                continue;
            }

            foreach (['name_en', 'passport_no', 'passport_expires_at'] as $field) {
                if (array_key_exists($field, $passenger)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function rules(): array
    {
        // ซื้อเป็นของขวัญ: ผู้ให้รู้แค่ชื่อผู้รับ — ข้อมูลผู้เดินทางที่เหลือ
        // จะถูกเติมจากโปรไฟล์ผู้รับตอนกดรับของขวัญ (GiftService::claim)
        $passengerRequired = $this->boolean('is_gift') ? 'nullable' : 'required';

        // เอกสารเดินทางบังคับเฉพาะทริปต่างประเทศ และเฉพาะการจองที่รู้ตัวผู้เดินทาง
        // แล้ว — ของขวัญยังไม่รู้ว่าใครไป จึงไปเก็บตอนผู้รับกดรับแทน
        // แอปเวอร์ชันเก่ายังไม่มีช่องให้กรอก จึงบังคับไม่ได้ (ดู clientKnowsPassportFields)
        $passportRequired = $this->isInternational()
            && ! $this->boolean('is_gift')
            && $this->clientKnowsPassportFields()
            ? 'required'
            : 'nullable';

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
            // เลขบัตรประชาชนบังคับเฉพาะคนสัญชาติไทย — ชาวต่างชาติที่ร่วมทริป
            // ยืนยันตัวด้วยพาสปอร์ตแทน (prepareForValidation เติม TH ให้เสมอ
            // เมื่อไม่ได้ส่งมา ค่าเดิมของทุกช่องทางจึงยังบังคับเหมือนเดิม)
            'passengers.*.id_card' => $this->boolean('is_gift')
                ? ['nullable', 'digits:13']
                : ['required_if:passengers.*.nationality,TH', 'nullable', 'digits:13'],
            'passengers.*.nationality' => ['required', 'string', 'size:2'],
            // ต้องสะกดตรงหน้าพาสปอร์ต จึงรับเฉพาะอักษรละติน
            'passengers.*.name_en' => [$passportRequired, 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z\s.\'-]+$/'],
            'passengers.*.passport_no' => [$passportRequired, 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'passengers.*.passport_expires_at' => [$passportRequired, 'nullable', 'date', 'after:today'],
            // Temporarily optional: the production mobile app does not send
            // birth_date yet. Revert to 'required' once the app ships.
            'passengers.*.birth_date' => ['nullable', 'date', 'before:today'],
            // เบอร์ไทยยังบังคับ 10 หลักเท่าเดิม (ตรวจใน withValidator) ส่วน
            // เบอร์ต่างประเทศยาวไม่เท่ากันและมีรหัสประเทศนำหน้า
            'passengers.*.phone' => [$passengerRequired, 'string', 'max:20', 'regex:/^\+?[0-9][0-9 -]{7,19}$/'],
            'passengers.*.email' => ['nullable', 'email', 'max:255'],
            'passengers.0.email' => ['required_if:booking_for,friend', 'nullable', 'email', 'max:255'],
            'passengers.*.blood_group' => [$passengerRequired, 'in:A,B,O,AB'],
            'passengers.*.allergies' => ['nullable', 'string', 'max:1000'],
            'passengers.*.halal_food' => [$passengerRequired, 'boolean'],
            'passengers.*.health_notes' => ['nullable', 'string'],
            'passengers.*.emergency_contact' => [$passengerRequired, 'string', 'max:255'],
            'passengers.*.emergency_phone' => [$passengerRequired, 'string', 'max:20', 'regex:/^\+?[0-9][0-9 -]{7,19}$/'],
            'passengers.*.pickup_point_id' => ['nullable', 'integer', 'exists:schedule_pickup_points,id'],
            'passengers.*.dive_cert_level' => ['nullable', 'string'],
            'passengers.*.cert_number' => ['nullable', 'string'],
            'passengers.*.weight' => ['nullable', 'numeric', 'min:0'],
            'is_group' => ['nullable', 'boolean'],
            // ข้ามการชำระเงินแล้วยืนยันทันที — ตรวจสิทธิ์แอดมินที่ BookingController
            'skip_payment' => ['nullable', 'boolean'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'group_notes' => ['nullable', 'string', 'max:1000'],
            'promotion_code' => ['nullable', 'string', 'max:50'],
            'is_join_trip' => ['nullable', 'boolean'],
            // ประเภทรถที่เลือก (รอบที่วิ่งทั้งบัสและตู้) — ความเป็นเจ้าของของรอบ
            // ตรวจใน BookingService ที่เดียว เพราะต้องอ่านโควตาที่นั่งพร้อมกัน
            'vehicle_option_id' => ['nullable', 'integer', 'exists:schedule_vehicle_options,id'],
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
            'passengers.*.id_card.required_if' => 'กรุณากรอกเลขบัตรประชาชน 13 หลัก',
            'passengers.*.name_en.required' => 'กรุณากรอกชื่อ-สกุลภาษาอังกฤษให้ตรงกับหน้าพาสปอร์ต',
            'passengers.*.name_en.regex' => 'ชื่อ-สกุลภาษาอังกฤษต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น',
            'passengers.*.passport_no.required' => 'กรุณากรอกเลขที่พาสปอร์ต',
            'passengers.*.passport_no.regex' => 'เลขที่พาสปอร์ตไม่ถูกต้อง',
            'passengers.*.passport_expires_at.required' => 'กรุณาระบุวันหมดอายุพาสปอร์ต',
            'passengers.*.passport_expires_at.after' => 'พาสปอร์ตหมดอายุแล้ว',
            'passengers.*.phone.regex' => 'เบอร์โทรศัพท์ไม่ถูกต้อง',
            'passengers.*.emergency_phone.regex' => 'เบอร์โทรผู้ติดต่อฉุกเฉินไม่ถูกต้อง',
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

            $this->validateThaiPhones($validator);
            $this->validatePassportValidity($validator, $schedule);

            // บังคับระบุจุดรับสำหรับการจองปกติ เมื่อรอบมีจุดขึ้นรถตั้งไว้ — กันทุกช่องทาง
            // ที่ยิงเข้ามาไม่ให้เกิดการจองที่ไม่มีจุดรับ (join trip ยกเว้น; รอบที่ไม่มีจุด
            // ตั้งไว้ก็ยกเว้นฝั่ง backend เพราะบางช่องทาง เช่น LIFF ไม่มีปุ่มปักหมุดเอง)
            // ทริปต่างประเทศยกเว้นด้วย — นัดเจอกันที่สนามบิน ไม่มีรถตู้วิ่งรับตามภาค
            // รอบที่บินไปก็เช่นกัน แม้ทริปจะอยู่ในประเทศ (บินไปเชียงราย) — การบิน
            // เป็นคุณสมบัติของรอบ ไม่ใช่ของทริป
            if (! $this->boolean('is_join_trip')
                && ! $schedule->trip->isInternational()
                && ! $schedule->isFlight()
                && $schedule->pickupPoints()->exists()) {
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

            // รอบที่ให้เลือกได้ว่าจะนั่งคันไหน ต้องเลือกก่อน — บังคับเฉพาะช่องทางที่
            // ส่งคีย์นี้มาแล้ว (= หน้าจอมีตัวเลือกให้กด) แอปรุ่นก่อนหน้าที่ยังไม่มี
            // ช่องเลือกจะตกไปที่ตัวเลือกราคาปกติแทน (BookingService::resolveVehicleOption)
            if (! $this->boolean('is_join_trip')
                && $this->has('vehicle_option_id')
                && blank($this->input('vehicle_option_id'))
                && $schedule->offersVehicleChoice()) {
                $validator->errors()->add('vehicle_option_id', 'กรุณาเลือกประเภทรถที่จะเดินทาง');
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

    /**
     * เบอร์ของคนสัญชาติไทยยังต้องเป็น 10 หลักเท่าเดิม
     *
     * กฎในตาราง rules() ผ่อนให้รองรับเบอร์ต่างประเทศ (มี + และความยาวไม่คงที่)
     * การรัดกลับเฉพาะสัญชาติไทยตรงนี้ทำให้ลูกค้าไทยพิมพ์เบอร์ผิดแล้วยังเจอ
     * ข้อความเดิม ไม่ใช่ปล่อยผ่านไปเป็นเบอร์ที่โทรไม่ติดตอนวันเดินทาง
     */
    private function validateThaiPhones(Validator $validator): void
    {
        foreach ($this->input('passengers', []) as $index => $passenger) {
            if (($passenger['nationality'] ?? Countries::HOME) !== Countries::HOME) {
                continue;
            }

            foreach (['phone' => 'เบอร์โทรศัพท์', 'emergency_phone' => 'เบอร์โทรผู้ติดต่อฉุกเฉิน'] as $field => $label) {
                $value = $passenger[$field] ?? null;
                if (blank($value)) {
                    continue; // ความจำเป็นของช่องนี้ให้ rules() ตัดสิน
                }

                if (! preg_match('/^[0-9]{10}$/', (string) $value)) {
                    $validator->errors()->add(
                        "passengers.{$index}.{$field}",
                        "{$label}ต้องเป็นตัวเลข 10 หลัก"
                    );
                }
            }
        }
    }

    /**
     * พาสปอร์ตต้องเหลืออายุอย่างน้อย 6 เดือนนับจากวันเดินทาง
     *
     * เป็นเกณฑ์ที่สายการบินและด่านตรวจคนเข้าเมืองส่วนใหญ่ใช้ ถ้าปล่อยผ่านตอนจอง
     * ลูกค้าจะไปรู้ตัวเอาที่เคาน์เตอร์เช็คอิน ซึ่งแก้อะไรไม่ทันแล้ว
     */
    private function validatePassportValidity(Validator $validator, TripSchedule $schedule): void
    {
        if (! $schedule->trip?->isInternational() || ! $schedule->departure_date) {
            return;
        }

        $minimumExpiry = $schedule->departure_date->copy()->addMonths(self::PASSPORT_VALIDITY_MONTHS);

        foreach ($this->input('passengers', []) as $index => $passenger) {
            $expiresAt = $passenger['passport_expires_at'] ?? null;
            if (blank($expiresAt)) {
                continue; // ความจำเป็นของช่องนี้ให้ rules() ตัดสิน
            }

            try {
                $expiry = Carbon::parse($expiresAt);
            } catch (\Throwable) {
                continue; // รูปแบบวันที่ผิด — กฎ 'date' รายงานไปแล้ว
            }

            if ($expiry->lt($minimumExpiry)) {
                $validator->errors()->add(
                    "passengers.{$index}.passport_expires_at",
                    'พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง '
                        .'(หมดอายุหลัง '.ThaiDate::short($minimumExpiry).')'
                );
            }
        }
    }
}
