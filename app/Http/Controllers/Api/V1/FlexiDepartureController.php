<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Services\FlexiDepartureService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ระบบ Flexi-Price (Go Together) — ลูกค้าดู/ตอบรับข้อเสนอไปต่อ (จ่ายส่วนต่างค่ารถ)
 * และผู้จัดสร้างข้อเสนอสำหรับรอบที่คนไม่ครบ
 */
class FlexiDepartureController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FlexiDepartureService $flexi,
    ) {}

    /**
     * ลูกค้า: ดูข้อเสนอ Flexi-Price ของการจอง + สถานะการตอบรับ + ความคืบหน้า
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($ref);

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูข้อมูลการจองนี้', 403);
        }

        return $this->success($this->flexi->overview($booking));
    }

    /**
     * ลูกค้า (เจ้าของการจอง): ยอมรับ/ปฏิเสธข้อเสนอไปต่อ
     */
    public function respond(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
        ]);

        $booking = $this->resolveBooking($ref);

        if ($booking->user_id !== $request->user()->id) {
            return $this->error('เฉพาะเจ้าของการจองเท่านั้นที่ตอบรับข้อเสนอได้', 403);
        }

        try {
            $this->flexi->respond($booking, (bool) $validated['accept']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->flexi->overview($booking->fresh()),
            $validated['accept'] ? 'ยืนยันการไปต่อแล้ว ขอบคุณครับ' : 'รับทราบการตอบกลับแล้ว',
        );
    }

    /**
     * ผู้จัด (admin|operator): สร้างข้อเสนอ Flexi-Price ให้รอบที่คนไม่ครบ
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'surcharge_per_person' => ['required', 'numeric', 'min:1'],
            'respond_by' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $schedule = TripSchedule::with('trip')->findOrFail($id);

        try {
            $offer = $this->flexi->createOffer(
                $schedule,
                (float) $validated['surcharge_per_person'],
                Carbon::parse($validated['respond_by']),
                $validated['reason'] ?? null,
                $request->user(),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'offer_id' => $offer->id,
            'status' => $offer->status,
            'consents' => $offer->consents()->count(),
        ], 'ส่งข้อเสนอ Flexi-Price ให้ลูกค้าแล้ว', 201);
    }

    private function resolveBooking(string $ref): Booking
    {
        return Booking::where('booking_ref', $ref)
            ->with(['schedule.trip'])
            ->firstOrFail();
    }
}
