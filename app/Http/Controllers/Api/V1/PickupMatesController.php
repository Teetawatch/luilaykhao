<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\PickupMatesService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "เพื่อนรอรถจุดเดียวกัน" — ใครอีกบ้างที่รอรถอยู่จุดเดียวกับใบจองนี้
 *
 * เจ้าของใบจองและเพื่อนที่ถูกเชิญเข้าใบจองเปิดได้เท่ากัน เพราะคนที่ยืนอยู่ที่
 * จุดรับจริงบ่อยครั้งไม่ใช่คนกดจอง (กติกาเดียวกับปุ่มบอกสถานะ)
 */
class PickupMatesController extends Controller
{
    use ApiResponse;

    public function __construct(private PickupMatesService $mates) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->findAccessible($request, $ref);

        if (! $booking) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        return $this->success($this->mates->forBooking($booking));
    }

    public function updateMine(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:'.PickupMatesService::NOTE_MAX],
            'hidden' => ['sometimes', 'boolean'],
        ]);

        $booking = $this->findAccessible($request, $ref);

        if (! $booking) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        try {
            $booking = $this->mates->updateMine($booking, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->mates->forBooking($booking), 'บันทึกแล้ว');
    }

    private function findAccessible(Request $request, string $ref): ?Booking
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with(['schedule.pickupPoints', 'user:id,name,nickname,avatar'])
            ->first();

        return $booking && $booking->isAccessibleByUser($request->user()->id) ? $booking : null;
    }
}
