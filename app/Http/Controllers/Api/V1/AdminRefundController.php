<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Services\BookingService;
use App\Services\RefundService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ขั้นตอนคืนเงินฝั่งทีมงาน — เปิดเรื่อง, เริ่มโอน, ตีกลับบัญชี, ยกเลิกทั้งรอบ
 * ขั้นสุดท้าย "โอนแล้ว + แนบสลิป" ยังเป็น POST admin/bookings/{ref}/refund เดิม
 * ดู RefundService
 */
class AdminRefundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RefundService $refunds,
        private BookingService $bookings,
    ) {}

    /**
     * POST admin/bookings/{ref}/refund/open — คืนเงินรายใบ: ใบที่ยังไม่ยกเลิกถูกยกเลิกให้ก่อน
     * แล้วลูกค้าได้ push ให้กรอกบัญชีในแอป (หรือไปขั้นรอโอนเลยถ้ามีบัญชีอยู่แล้ว)
     */
    public function open(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'refund_amount' => ['required', 'numeric', 'min:1'],
            // เราเป็นฝ่ายยกเลิก คืนทุกบาท — อีเมลไม่แสดงนโยบาย 80/50/0
            'full' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if (in_array($booking->status, Booking::MODIFIABLE_STATUSES, true)) {
            $booking = $this->bookings->cancelBooking($booking, $data['reason'] ?? null, notify: false);
        } elseif (! empty($data['reason'])) {
            $booking->forceFill(['cancellation_reason' => $data['reason']])->save();
        }

        try {
            $booking = $this->refunds->open($booking, (float) $data['refund_amount'], (bool) ($data['full'] ?? false));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new BookingResource($booking->fresh(['schedule.trip', 'passengers'])),
            $booking->refund_status === RefundService::AWAITING_ACCOUNT
                ? 'เปิดเรื่องคืนเงินแล้ว แจ้งลูกค้าให้กรอกบัญชีในแอป'
                : 'เปิดเรื่องคืนเงินแล้ว ลูกค้ามีบัญชีอยู่แล้ว พร้อมโอน',
        );
    }

    /** POST admin/bookings/{ref}/refund/processing — แจ้งลูกค้าว่ากำลังโอน */
    public function processing(string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        try {
            $booking = $this->refunds->markProcessing($booking);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new BookingResource($booking->fresh(['schedule.trip', 'passengers'])),
            'แจ้งลูกค้าว่ากำลังโอนแล้ว',
        );
    }

    /** POST admin/bookings/{ref}/refund/reject-account — บัญชีโอนไม่ได้ ขอให้ลูกค้ากรอกใหม่ */
    public function rejectAccount(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        try {
            $booking = $this->refunds->rejectAccount($booking, $data['note']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            new BookingResource($booking->fresh(['schedule.trip', 'passengers'])),
            'แจ้งลูกค้าให้กรอกบัญชีใหม่แล้ว',
        );
    }

    /** GET admin/schedules/{id}/cancel-refund — ก่อนกด: จะยกเลิกกี่ใบ คืนเงินรวมเท่าไร */
    public function schedulePreview(int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        $bookings = $schedule->bookings()
            ->whereIn('status', Booking::MODIFIABLE_STATUSES)
            ->get(['id', 'paid_amount']);
        $paid = $bookings->filter(fn (Booking $b) => (float) $b->paid_amount > 0);

        return $this->success([
            'schedule_id' => $schedule->id,
            'status' => $schedule->status,
            'bookings' => $bookings->count(),
            'paid_bookings' => $paid->count(),
            'refund_total' => round((float) $paid->sum('paid_amount'), 2),
        ]);
    }

    /** POST admin/schedules/{id}/cancel-refund — ทริปไม่ออก ยกเลิกทั้งรอบ คืนเงินเต็มจำนวนทุกคน */
    public function cancelSchedule(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ]);

        $schedule = TripSchedule::findOrFail($id);

        try {
            $result = $this->refunds->cancelSchedule($schedule, $data['reason']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $result,
            "ยกเลิกรอบแล้ว · {$result['cancelled']} การจอง · เปิดเรื่องคืนเงิน {$result['refunds']} รายการ รวม ฿"
                .number_format($result['refund_total'], 0),
        );
    }
}
