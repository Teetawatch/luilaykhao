<?php

namespace App\Http\Controllers;

use App\Models\BookingDocument;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Rules\ThaiName;
use App\Services\BookingDocumentService;
use App\Support\Countries;
use App\Support\TripDocumentRequirements;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * หน้าให้เพื่อนร่วมทางกรอกข้อมูลของตัวเอง เข้าผ่านลิงก์เฉพาะคน
 *
 * แก้ปัญหาที่คนจองต้องไล่ถามเลขบัตรประชาชน กรุ๊ปเลือด และโรคประจำตัวของเพื่อน
 * ทางแชท — ซึ่งเป็นข้อมูลที่คนไม่อยากพิมพ์ส่งในกลุ่ม
 *
 * เป็นหน้าเว็บธรรมดา ไม่ต้องล็อกอินและไม่ต้องมีแอป เพื่อนเปิดลิงก์จากที่ไหนก็ได้
 * รวมถึงเบราว์เซอร์ใน LINE ตัวลิงก์เองคือความลับ จึงมีวันหมดอายุและถูกเพิกถอน
 * ทันทีที่กรอกเสร็จ
 */
class PublicPassengerFillController extends Controller
{
    public function __construct(private readonly BookingDocumentService $files) {}

    /** บัตรหน้า/หลังที่คนนี้แนบไว้แล้ว [key => true] */
    private function idFiles(BookingPassenger $passenger): array
    {
        return BookingDocument::where('booking_passenger_id', $passenger->id)
            ->whereIn('requirement_key', TripDocumentRequirements::BORDER_PASS_KEYS)
            ->pluck('requirement_key')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    public function show(string $token): View
    {
        $passenger = $this->resolve($token);

        return view('passenger-fill.form', [
            'passenger' => $passenger,
            'booking' => $passenger->booking,
            'trip' => $passenger->booking?->schedule?->trip,
            'idFiles' => $this->idFiles($passenger),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $passenger = $this->resolve($token);

        // ทริปต่างประเทศต้องได้เอกสารเดินทางจากเพื่อนด้วย ไม่งั้นคนจองต้องไปไล่
        // ถามเลขพาสปอร์ตทางแชทอยู่ดี ซึ่งเป็นปัญหาเดิมที่หน้านี้ตั้งใจแก้
        // (เว้นทริปที่ใช้ฉบับจริงหน้างาน และคนที่เลือกให้ทีมงานทำ Border Pass)
        $trip = $passenger->booking?->schedule?->trip;
        $passportRequired = $trip?->passportRule() ?? 'nullable';

        // ชื่อไปทำประกัน ซึ่งรับเฉพาะชื่อไทยตามบัตร — ชาวต่างชาติข้าม
        $isThai = ($passenger->nationality ?: Countries::HOME) === Countries::HOME;
        $offersBorderPass = $isThai && $trip?->offersBorderPass();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', ...($isThai ? [new ThaiName] : [])],
            'nickname' => ['nullable', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            // Border Pass ยื่นทำจากเลขบัตรประชาชน — ขาดเลขนี้ทีมงานทำให้ไม่ได้
            'id_card' => ['nullable', 'string', 'max:20', 'required_if:travel_document,'.Trip::DOC_BORDER_PASS],
            'travel_document' => $offersBorderPass
                ? ['required', Rule::in([Trip::DOC_PASSPORT, Trip::DOC_BORDER_PASS])]
                : ['nullable', Rule::in([Trip::DOC_PASSPORT, Trip::DOC_BORDER_PASS])],
            'name_en' => [$passportRequired, 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z\s.\'-]+$/'],
            'passport_no' => [$passportRequired, 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'passport_expires_at' => [$passportRequired, 'nullable', 'date', 'after:today'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'blood_group' => ['nullable', Rule::in(['A', 'B', 'AB', 'O', ''])],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'health_notes' => ['nullable', 'string', 'max:500'],
            'halal_food' => ['nullable', 'boolean'],
            // บัตรประชาชนหน้า/หลัง — ด่านใช้เป็นหลักฐานทำ Border Pass (แนบไว้แล้วไม่ต้องแนบซ้ำ)
            'border_pass_front' => [
                ...($this->idFiles($passenger)[TripDocumentRequirements::BORDER_PASS_FRONT] ?? false ? [] : ['required_if:travel_document,'.Trip::DOC_BORDER_PASS]),
                'nullable', 'file', 'mimes:'.implode(',', BookingDocumentService::ALLOWED_MIMES), 'max:'.BookingDocumentService::MAX_SIZE_KB,
            ],
            'border_pass_back' => [
                ...($this->idFiles($passenger)[TripDocumentRequirements::BORDER_PASS_BACK] ?? false ? [] : ['required_if:travel_document,'.Trip::DOC_BORDER_PASS]),
                'nullable', 'file', 'mimes:'.implode(',', BookingDocumentService::ALLOWED_MIMES), 'max:'.BookingDocumentService::MAX_SIZE_KB,
            ],
        ], [
            'border_pass_front.required_if' => 'กรุณาแนบรูปบัตรประชาชนด้านหน้า ด่านใช้เป็นหลักฐานทำ Border Pass',
            'border_pass_back.required_if' => 'กรุณาแนบรูปบัตรประชาชนด้านหลัง ด่านใช้เป็นหลักฐานทำ Border Pass',
            'border_pass_front.mimes' => 'บัตรประชาชนรองรับไฟล์รูปภาพ และ PDF เท่านั้น',
            'border_pass_back.mimes' => 'บัตรประชาชนรองรับไฟล์รูปภาพ และ PDF เท่านั้น',
            'border_pass_front.max' => 'ไฟล์บัตรประชาชนต้องไม่เกิน 10 MB',
            'border_pass_back.max' => 'ไฟล์บัตรประชาชนต้องไม่เกิน 10 MB',
            'name.required' => 'กรุณากรอกชื่อ-นามสกุล',
            'phone.required' => 'กรุณากรอกเบอร์โทรศัพท์',
            'birth_date.before' => 'วันเกิดต้องเป็นวันในอดีต',
            'name_en.required' => 'กรุณากรอกชื่อ-สกุลภาษาอังกฤษให้ตรงกับหน้าพาสปอร์ต',
            'name_en.regex' => 'ชื่อ-สกุลภาษาอังกฤษต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น',
            'passport_no.required' => 'กรุณากรอกเลขที่พาสปอร์ต',
            'passport_no.regex' => 'เลขที่พาสปอร์ตไม่ถูกต้อง',
            'passport_expires_at.required' => 'กรุณาระบุวันหมดอายุพาสปอร์ต',
            'passport_expires_at.after' => 'พาสปอร์ตหมดอายุแล้ว',
            'travel_document.required' => 'กรุณาเลือกว่ามีพาสปอร์ตหรือให้ทีมงานทำ Border Pass',
            'id_card.required_if' => 'กรุณากรอกเลขบัตรประชาชน ทีมงานใช้ยื่นทำ Border Pass',
        ]);

        // เกณฑ์ 6 เดือนเหมือนตอนจอง — เพื่อนที่กรอกทีหลังต้องผ่านด่านเดียวกัน
        if ($trip?->needsPassportFor($data['travel_document'] ?? null, $passenger->nationality)
            && filled($data['passport_expires_at'] ?? null)) {
            $departure = $passenger->booking?->schedule?->departure_date;
            if ($departure && Carbon::parse($data['passport_expires_at'])->lt($departure->copy()->addMonths(6))) {
                return back()
                    ->withInput()
                    ->withErrors(['passport_expires_at' => 'พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง']);
            }
        }

        if ($trip) {
            $data = $trip->applyTravelDocument([...$data, 'nationality' => $passenger->nationality]);
        }
        unset($data['border_pass_front'], $data['border_pass_back']);

        $passenger->fill([
            ...$data,
            'halal_food' => $request->boolean('halal_food'),
            'self_filled_at' => now(),
            // ลิงก์ใช้ได้ครั้งเดียว — กรอกเสร็จแล้วเปิดซ้ำไม่ได้ เพราะมันเปิด
            // ข้อมูลบัตรประชาชนของคนคนนั้นให้ใครก็ตามที่ถือลิงก์อยู่
            'self_fill_token' => null,
            'self_fill_expires_at' => null,
        ])->save();

        // ไฟล์บัตรแนบหลังบันทึกคำตอบ Border Pass แล้ว — ตัวเก็บไฟล์รับเฉพาะคนที่เป็น Border Pass
        if ($passenger->travel_document === Trip::DOC_BORDER_PASS && $passenger->booking) {
            $booking = $passenger->booking->load(['passengers', 'schedule.trip']);
            foreach (['border_pass_front' => TripDocumentRequirements::BORDER_PASS_FRONT, 'border_pass_back' => TripDocumentRequirements::BORDER_PASS_BACK] as $field => $key) {
                if ($request->hasFile($field)) {
                    $this->files->store($booking, $passenger->fresh(), $key, $request->file($field), null);
                }
            }
        }

        return redirect()
            ->route('public.passenger-fill.done')
            ->with('passenger_name', $passenger->name)
            // มาจากลิงก์ของเพื่อน — พากลับไปต่อที่บัตรขึ้นรถ/เข้าแอป ไม่ใช่จบที่ทางตัน
            ->with('pass_url', $passenger->passUrl());
    }

    public function done(): View
    {
        return view('passenger-fill.done', [
            'name' => session('passenger_name'),
            'passUrl' => session('pass_url'),
        ]);
    }

    /**
     * ลิงก์ที่หมดอายุหรือถูกใช้ไปแล้วตอบ 404 เหมือนกับลิงก์มั่ว — ไม่บอกว่า
     * "เคยมีอยู่" เพื่อไม่ให้เดาสุ่มแล้วรู้ว่าโทเคนไหนเคยถูกต้อง
     */
    private function resolve(string $token): BookingPassenger
    {
        $passenger = BookingPassenger::with('booking.schedule.trip')
            ->where('self_fill_token', $token)
            ->first();

        if (! $passenger) {
            throw new NotFoundHttpException('ลิงก์นี้ใช้ไม่ได้แล้ว');
        }

        if ($passenger->self_fill_expires_at && $passenger->self_fill_expires_at->isPast()) {
            throw new NotFoundHttpException('ลิงก์นี้หมดอายุแล้ว');
        }

        if (! $passenger->booking) {
            throw new NotFoundHttpException('ไม่พบการจองของลิงก์นี้');
        }

        if ($passenger->booking->status === 'cancelled') {
            throw new NotFoundHttpException('การจองนี้ถูกยกเลิกแล้ว');
        }

        return $passenger;
    }
}
