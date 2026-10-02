<?php

namespace App\Http\Requests\Booking;

use App\Models\SeatHandover;
use App\Rules\ThaiIdCard;
use App\Rules\ThaiName;
use App\Services\SeatHandoverService;
use App\Support\Countries;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ข้อมูลผู้เดินทางของคนที่มารับที่นั่งต่อ — ด่านเดียวกับตอนจอง (CreateBookingRequest)
 * เพราะรายชื่อชุดนี้ไปทำประกันและขึ้นรถจริง คนที่มาทีหลังต้องไม่หลุดด่านที่คนจอง
 * ตั้งแต่แรกต้องผ่าน
 *
 * ต่างจากตอนจองตรงที่วันเกิดบังคับเลย — ตอนจองผ่อนไว้ให้แอปรุ่นเก่าที่ยังไม่มีช่อง
 * แต่ทางนี้เป็นของใหม่ทุกช่องทาง
 */
class ClaimSeatHandoverRequest extends FormRequest
{
    private const PASSPORT_VALIDITY_MONTHS = 6;

    private ?SeatHandover $handover = null;

    private bool $handoverResolved = false;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['phone', 'emergency_phone', 'id_card'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = preg_replace('/[\s-]/', '', $this->input($field));
            }
        }

        if (blank($this->input('nationality'))) {
            $merge['nationality'] = Countries::HOME;
        } elseif (is_string($this->input('nationality'))) {
            $merge['nationality'] = strtoupper(trim($this->input('nationality')));
        }

        if (is_string($this->input('passport_no'))) {
            $merge['passport_no'] = strtoupper(preg_replace('/\s/', '', $this->input('passport_no')));
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $isThai = $this->input('nationality', Countries::HOME) === Countries::HOME;
        $passport = $this->isInternational() ? 'required' : 'nullable';
        $womenOnly = (bool) $this->handover()?->booking?->schedule?->trip?->is_women_only;

        return [
            'title' => ['required', 'string', 'max:50', ...($womenOnly ? [Rule::in(SeatHandoverService::WOMEN_ONLY_TITLES)] : [])],
            'name' => ['required', 'string', 'max:255', ...($isThai ? [new ThaiName] : [])],
            'nickname' => ['required', 'string', 'max:100'],
            'nationality' => ['required', 'string', 'size:2'],
            'id_card' => [$isThai ? 'required' : 'nullable', 'nullable', 'string', 'max:20', ...($isThai ? [new ThaiIdCard] : [])],
            'birth_date' => ['required', 'date', 'before:today'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 -]{7,19}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'blood_group' => ['required', 'in:A,B,O,AB'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'health_notes' => ['nullable', 'string', 'max:1000'],
            'halal_food' => ['required', 'boolean'],
            'emergency_contact' => ['required', 'string', 'max:255'],
            'emergency_phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 -]{7,19}$/'],
            'name_en' => [$passport, 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z\s.\'-]+$/'],
            'passport_no' => [$passport, 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'passport_expires_at' => [$passport, 'nullable', 'date', 'after:today'],
            'accept_terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:20'],
            'channel' => ['nullable', 'string', 'in:web,app,liff'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'กรุณาเลือกคำนำหน้าชื่อ',
            'title.in' => "ทริปนี้เป็นทริปสำหรับผู้หญิงเท่านั้น กรุณาเลือกคำนำหน้าชื่อเป็น 'นาง' หรือ 'นางสาว'",
            'name.required' => 'กรุณากรอกชื่อ-นามสกุล',
            'nickname.required' => 'กรุณากรอกชื่อเล่น',
            'id_card.required' => 'กรุณากรอกเลขบัตรประชาชน 13 หลัก',
            'birth_date.required' => 'กรุณาระบุวัน/เดือน/ปีเกิด',
            'birth_date.before' => 'วัน/เดือน/ปีเกิดไม่ถูกต้อง',
            'phone.required' => 'กรุณากรอกเบอร์โทรศัพท์',
            'phone.regex' => 'เบอร์โทรศัพท์ไม่ถูกต้อง',
            'blood_group.required' => 'กรุณาเลือกกรุ๊ปเลือด',
            'halal_food.required' => 'กรุณาระบุว่าทานอาหารฮาลาลหรือไม่',
            'emergency_contact.required' => 'กรุณากรอกชื่อผู้ติดต่อฉุกเฉิน',
            'emergency_phone.required' => 'กรุณากรอกเบอร์ผู้ติดต่อฉุกเฉิน',
            'emergency_phone.regex' => 'เบอร์โทรผู้ติดต่อฉุกเฉินไม่ถูกต้อง',
            'name_en.required' => 'กรุณากรอกชื่อ-สกุลภาษาอังกฤษให้ตรงกับหน้าพาสปอร์ต',
            'name_en.regex' => 'ชื่อ-สกุลภาษาอังกฤษต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น',
            'passport_no.required' => 'กรุณากรอกเลขที่พาสปอร์ต',
            'passport_no.regex' => 'เลขที่พาสปอร์ตไม่ถูกต้อง',
            'passport_expires_at.required' => 'กรุณาระบุวันหมดอายุพาสปอร์ต',
            'passport_expires_at.after' => 'พาสปอร์ตหมดอายุแล้ว',
            'accept_terms.accepted' => 'กรุณายอมรับเงื่อนไขการเดินทางก่อนรับที่นั่ง',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $version = $this->input('terms_version');
            if (filled($version) && $version !== config('legal.terms_version')) {
                $validator->errors()->add(
                    'terms_version',
                    'เงื่อนไขการเดินทางเพิ่งมีการปรับปรุง กรุณาโหลดหน้านี้ใหม่แล้วอ่านอีกครั้งก่อนรับที่นั่ง',
                );
            }

            if ($this->input('nationality') === Countries::HOME) {
                foreach (['phone' => 'เบอร์โทรศัพท์', 'emergency_phone' => 'เบอร์โทรผู้ติดต่อฉุกเฉิน'] as $field => $label) {
                    $value = $this->input($field);
                    if (filled($value) && ! preg_match('/^[0-9]{10}$/', (string) $value)) {
                        $validator->errors()->add($field, "{$label}ต้องเป็นตัวเลข 10 หลัก");
                    }
                }
            }

            $departure = $this->handover()?->booking?->schedule?->departure_date;
            $expiresAt = $this->input('passport_expires_at');
            if ($this->isInternational() && $departure && filled($expiresAt)) {
                try {
                    $expiry = Carbon::parse($expiresAt);
                } catch (\Throwable) {
                    return;
                }

                $minimum = $departure->copy()->addMonths(self::PASSPORT_VALIDITY_MONTHS);
                if ($expiry->lt($minimum)) {
                    $validator->errors()->add(
                        'passport_expires_at',
                        'พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง (หมดอายุหลัง '.ThaiDate::short($minimum).')',
                    );
                }
            }
        });
    }

    private function isInternational(): bool
    {
        return (bool) $this->handover()?->booking?->schedule?->trip?->isInternational();
    }

    private function handover(): ?SeatHandover
    {
        if (! $this->handoverResolved) {
            $this->handoverResolved = true;
            $this->handover = SeatHandover::with('booking.schedule.trip')
                ->where('token', (string) $this->route('token'))
                ->first();
        }

        return $this->handover;
    }
}
