<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\ForceMajeureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * หน้าเลือกรอบใหม่แบบไม่ต้องล็อกอิน (/reschedule/{token}) — สำหรับใบจองที่รอบเดิม
 * ถูกเลื่อนเพราะเหตุสุดวิสัย ลิงก์นี้อยู่ในอีเมล/SMS และทีมงานคัดลอกส่งทางไลน์ได้
 *
 * มีเพราะลูกค้าส่วนใหญ่โทรหรือทักไลน์ให้ทีมงานจองให้ ใบจองอยู่ในบัญชีเงาที่ยัง
 * ล็อกอินไม่ได้ — ลิงก์ /my-bookings จึงพาเขาไปทางตัน กติกาทั้งหมด (กรอบเวลา ที่นั่ง
 * ราคาเดิม จัดที่นั่งให้) มาจาก BookingService::rescheduleBooking() ตัวเดียวกับแอป
 */
class PublicRescheduleController extends Controller
{
    public function __construct(
        private ForceMajeureService $forceMajeure,
        private BookingService $bookings,
    ) {}

    public function show(string $token): View
    {
        $booking = $this->resolveBooking($token);

        return view('reschedule.show', [
            'booking' => $booking,
            'token' => $token,
            'fm' => ForceMajeureService::customerPayload($booking),
            'rounds' => $booking->canChooseForceMajeureRound()
                ? $this->forceMajeure->eligibleRounds($booking)
                : collect(),
        ]);
    }

    public function choose(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'target_schedule_id' => ['required', 'integer'],
        ], [
            'target_schedule_id.required' => 'กรุณาเลือกรอบเดินทาง',
        ]);

        $booking = $this->resolveBooking($token);

        if (! $booking->canChooseForceMajeureRound()) {
            return redirect()->route('public.reschedule.show', $token);
        }

        try {
            $this->bookings->rescheduleBooking($booking, (int) $validated['target_schedule_id']);
        } catch (\Exception $e) {
            return redirect()->route('public.reschedule.show', $token)
                ->withErrors(['target_schedule_id' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('public.reschedule.show', $token)->with('moved', true);
    }

    /**
     * ใบที่เคยได้สิทธิ์นี้เท่านั้น (ยกเลิกแล้วก็ยังเปิดได้ เพื่อบอกสถานะ ไม่ใช่ 404 เงียบ)
     */
    private function resolveBooking(string $token): Booking
    {
        return Booking::where('reschedule_token', strtolower(trim($token)))
            ->with(['schedule.trip', 'passengers', 'pickupPoint', 'forceMajeureSchedule'])
            ->firstOrFail();
    }
}
