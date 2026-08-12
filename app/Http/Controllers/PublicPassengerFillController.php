<?php

namespace App\Http\Controllers;

use App\Models\BookingPassenger;
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
    public function show(string $token): View
    {
        $passenger = $this->resolve($token);

        return view('passenger-fill.form', [
            'passenger' => $passenger,
            'booking' => $passenger->booking,
            'trip' => $passenger->booking?->schedule?->trip,
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $passenger = $this->resolve($token);

        // ทริปต่างประเทศต้องได้เอกสารเดินทางจากเพื่อนด้วย ไม่งั้นคนจองต้องไปไล่
        // ถามเลขพาสปอร์ตทางแชทอยู่ดี ซึ่งเป็นปัญหาเดิมที่หน้านี้ตั้งใจแก้
        $isInternational = (bool) $passenger->booking?->schedule?->trip?->isInternational();
        $passportRequired = $isInternational ? 'required' : 'nullable';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'id_card' => ['nullable', 'string', 'max:20'],
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
        ], [
            'name.required' => 'กรุณากรอกชื่อ-นามสกุล',
            'phone.required' => 'กรุณากรอกเบอร์โทรศัพท์',
            'birth_date.before' => 'วันเกิดต้องเป็นวันในอดีต',
            'name_en.required' => 'กรุณากรอกชื่อ-สกุลภาษาอังกฤษให้ตรงกับหน้าพาสปอร์ต',
            'name_en.regex' => 'ชื่อ-สกุลภาษาอังกฤษต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น',
            'passport_no.required' => 'กรุณากรอกเลขที่พาสปอร์ต',
            'passport_no.regex' => 'เลขที่พาสปอร์ตไม่ถูกต้อง',
            'passport_expires_at.required' => 'กรุณาระบุวันหมดอายุพาสปอร์ต',
            'passport_expires_at.after' => 'พาสปอร์ตหมดอายุแล้ว',
        ]);

        // เกณฑ์ 6 เดือนเหมือนตอนจอง — เพื่อนที่กรอกทีหลังต้องผ่านด่านเดียวกัน
        if ($isInternational && filled($data['passport_expires_at'] ?? null)) {
            $departure = $passenger->booking?->schedule?->departure_date;
            if ($departure && Carbon::parse($data['passport_expires_at'])->lt($departure->copy()->addMonths(6))) {
                return back()
                    ->withInput()
                    ->withErrors(['passport_expires_at' => 'พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง']);
            }
        }

        $passenger->fill([
            ...$data,
            'halal_food' => $request->boolean('halal_food'),
            'self_filled_at' => now(),
            // ลิงก์ใช้ได้ครั้งเดียว — กรอกเสร็จแล้วเปิดซ้ำไม่ได้ เพราะมันเปิด
            // ข้อมูลบัตรประชาชนของคนคนนั้นให้ใครก็ตามที่ถือลิงก์อยู่
            'self_fill_token' => null,
            'self_fill_expires_at' => null,
        ])->save();

        return redirect()
            ->route('public.passenger-fill.done')
            ->with('passenger_name', $passenger->name);
    }

    public function done(): View
    {
        return view('passenger-fill.done', [
            'name' => session('passenger_name'),
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
