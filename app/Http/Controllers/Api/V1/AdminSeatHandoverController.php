<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\SeatHandoverService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ส่งต่อที่นั่งฝั่งทีมงาน — ลูกค้าทักแชทมาว่าไปไม่ได้ ทีมงานออกลิงก์ให้แล้วคัดลอก
 * ส่งกลับไปในแชทได้เลย (ลูกค้าหลายคนจองทางไลน์และไม่เคยเปิดหน้าการจองของตัวเอง)
 * พร้อมประวัติว่าใครส่งต่อให้ใคร ไว้ตอบเวลารายชื่อประกันไม่ตรง
 */
class AdminSeatHandoverController extends Controller
{
    use ApiResponse;

    public function __construct(private SeatHandoverService $handovers) {}

    public function index(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        return $this->success($this->handovers->overview($booking, $request->user(), asStaff: true));
    }

    public function store(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'passenger_id' => ['required', 'integer'],
            'transfers_ownership' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        try {
            $handover = $this->handovers->create(
                $booking,
                (int) $validated['passenger_id'],
                $request->user(),
                (bool) ($validated['transfers_ownership'] ?? false),
                $validated['note'] ?? null,
                asStaff: true,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->handovers->present($handover->load('creator'), withLink: true),
            'สร้างลิงก์ส่งต่อที่นั่งแล้ว คัดลอกส่งให้ลูกค้าได้เลย',
            201,
        );
    }

    public function destroy(Request $request, string $ref, int $handoverId): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        try {
            $this->handovers->cancel($booking, $handoverId, $request->user(), asStaff: true);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'ยกเลิกลิงก์แล้ว');
    }
}
