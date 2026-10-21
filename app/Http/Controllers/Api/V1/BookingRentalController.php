<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingRentalOrder;
use App\Services\RentalOrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "เช่าอุปกรณ์เพิ่ม" หลังจอง — ลูกค้าเลือกเองแล้วจ่ายผ่านยอดเพิ่มเติม
 *
 * คนจองและเพื่อนที่อยู่ในใบจองสั่งได้ เหมือนที่จ่ายยอดเพิ่มเติมได้
 */
class BookingRentalController extends Controller
{
    use ApiResponse;

    public function __construct(private RentalOrderService $rentals) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->booking($request, $ref);
        if (! $booking) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        return $this->success($this->rentals->overview($booking));
    }

    public function store(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.key' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $booking = $this->booking($request, $ref);
        if (! $booking) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        $quantities = [];
        foreach ($validated['items'] as $item) {
            $quantities[$item['key']] = ($quantities[$item['key']] ?? 0) + (int) $item['quantity'];
        }

        try {
            $order = $this->rentals->place($booking, $request->user(), $quantities);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->payload($booking, $order), 'เพิ่มอุปกรณ์เช่าแล้ว ชำระเงินเพื่อยืนยันรายการ', 201);
    }

    public function cancel(Request $request, string $ref, int $orderId): JsonResponse
    {
        $booking = $this->booking($request, $ref);
        $order = $booking
            ? BookingRentalOrder::where('booking_id', $booking->id)->whereKey($orderId)->first()
            : null;

        if (! $order) {
            return $this->error('ไม่พบรายการเช่านี้', 404);
        }

        try {
            $order = $this->rentals->cancel($order);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->payload($booking, $order), 'ยกเลิกรายการเช่าแล้ว');
    }

    /** @return array<string, mixed> */
    private function payload(Booking $booking, BookingRentalOrder $order): array
    {
        $booking = $booking->fresh(['seats', 'schedule.trip', 'passengers', 'installmentPayments']);

        return [
            'order' => $this->rentals->present($order),
            'overview' => $this->rentals->overview($booking),
            'booking' => new BookingResource($booking),
        ];
    }

    private function booking(Request $request, string $ref): ?Booking
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with(['schedule.trip', 'installmentPayments'])
            ->first();

        return $booking && $booking->isAccessibleByUser($request->user()->id) ? $booking : null;
    }
}
