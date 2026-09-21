<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\PickupStatusService;
use App\Services\TripBriefService;
use App\Support\TripBriefCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * "ใบเดินทาง" สาธารณะ /t/{token} — กำหนดการ จุดขึ้นรถ รถ และเบอร์ทีมงานของรอบ
 * รวมอยู่หน้าเดียว เปิดจากลิงก์ในอีเมลหรือ SMS ได้เลย ไม่ต้องล็อกอิน ไม่ต้องมีแอป
 *
 * server-rendered ทั้งหน้าโดยตั้งใจ: ลูกค้ากลุ่มที่ต้องใช้หน้านี้มากที่สุดคือคนที่
 * เปิดมันตอนยืนรออยู่ริมถนนตีสี่ ด้วยสัญญาณเส้นเดียว — หน้าเดียวที่มาครบในครั้งเดียว
 * ชนะ SPA ที่ต้องโหลด JS ก่อนเสมอ
 */
class PublicTripBriefController extends Controller
{
    public function __construct(private TripBriefService $briefs) {}

    public function show(string $token): Response
    {
        $brief = $this->briefs->forToken($token);

        if ($brief === null) {
            return response()->view('trip-brief', ['brief' => null], 404);
        }

        $this->markRead($token);

        return response()->view('trip-brief', ['brief' => $brief]);
    }

    /**
     * ไฟล์ปฏิทินของรอบนี้ — ปุ่ม "เพิ่มลงปฏิทิน" บนใบเดินทางชี้มาที่นี่
     *
     * ต้องผ่าน isViewable() เหมือนตัวหน้าเว็บ ลิงก์ที่หมดอายุแล้วจึงไม่แอบเป็น
     * ช่องทางดูดข้อมูลรอบที่จบไปแล้วออกไป
     */
    public function calendar(string $token): Response
    {
        $brief = $this->briefs->forToken($token);

        if ($brief === null) {
            return response('ไม่พบใบเดินทางนี้', 404);
        }

        return response(TripBriefCalendar::build($brief), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.TripBriefCalendar::filename($brief).'"',
        ]);
    }

    /**
     * ลูกค้ากด "รับทราบแล้ว"
     *
     * ต่างจาก [brief_read_at] ที่ระบบบันทึกเองตอนลิงก์ถูกเปิด — ลิงก์อาจถูกเปิดโดย
     * คนที่บ้านที่ลูกค้าส่งต่อให้ก็ได้ ปุ่มนี้คือคำตอบจากคนจริงว่าอ่านแล้ว ทีมงานจึง
     * เหลือรายชื่อที่ต้องโทรตามสั้นลงมาก แทนที่จะโทรทั้งรอบ
     *
     * กดซ้ำไม่นับใหม่ เวลาที่เก็บคือ "ครั้งแรกที่รับทราบ" ไม่ใช่ครั้งล่าสุดที่กดปุ่ม
     */
    public function acknowledge(string $token): RedirectResponse
    {
        $booking = Booking::where('brief_token', $token)->first();

        if ($booking && $this->briefs->isViewable($booking) && $booking->brief_ack_at === null) {
            // saveQuietly: การกดปุ่มอ่านแล้วไม่ใช่การเปลี่ยนแปลงของการจอง จึงไม่ควร
            // ไปปลุกงานเบื้องหลังที่เฝ้าดูใบจองอยู่ (แจ้งเตือน/การ์ดวันเดินทาง)
            $booking->forceFill(['brief_ack_at' => now()])->saveQuietly();
        }

        return redirect()
            ->route('public.trip-brief.show', $token)
            ->with('acked', true);
    }

    /**
     * ลูกค้ากดบอกสถานะที่จุดนัด — กำลังไป / ถึงแล้ว / อาจสาย
     *
     * ปุ่มชุดเดียวกับในแอป แต่เปิดให้คนที่ไม่มีแอปกดได้ด้วย ซึ่งเป็นกลุ่มที่เช้า
     * วันเดินทางสตาฟต้องไล่โทรตามมากที่สุดพอดี
     *
     * กติกาว่ากดได้ตอนไหน/สถานะไหนมีผลอะไร อยู่ที่ PickupStatusService ทั้งหมด
     * ที่นี่แค่ส่งต่อแล้วเอาข้อความภาษาไทยของมันกลับไปขึ้นบนหน้าเดิม
     */
    public function reportPickupStatus(
        Request $request,
        string $token,
        PickupStatusService $pickupStatus,
    ): RedirectResponse {
        $booking = Booking::with(['schedule.trip', 'passengers', 'pickupPoint', 'user'])
            ->where('brief_token', $token)
            ->first();

        if (! $booking || ! $this->briefs->isViewable($booking)) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(Booking::PICKUP_STATUSES)],
            'eta_minutes' => ['nullable', 'integer', 'min:5', 'max:180'],
        ]);

        $back = redirect()->route('public.trip-brief.show', $token);

        try {
            $pickupStatus->report(
                $booking,
                $validated['status'],
                $validated['eta_minutes'] ?? null,
            );
        } catch (\Exception $e) {
            return $back->with('pickup_error', $e->getMessage());
        }

        return $back->with('pickup_saved', true);
    }

    /**
     * บันทึกว่าลิงก์ถูกเปิดครั้งแรกเมื่อไหร่ — เขียนครั้งเดียวต่อใบ ไม่ใช่ทุกครั้งที่
     * เปิด เพราะคำถามของทีมงานคือ "ถึงมือลูกค้าหรือยัง" ไม่ใช่ "เปิดไปกี่ครั้ง"
     */
    private function markRead(string $token): void
    {
        Booking::where('brief_token', $token)
            ->whereNull('brief_read_at')
            ->update(['brief_read_at' => now()]);
    }
}
