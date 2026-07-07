<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSplitShare;
use App\Services\SplitPaymentService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use App\Traits\ResolvesTransferDatetime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ระบบแบ่งจ่ายกลุ่ม — เจ้าของแบ่งยอดคงเหลือให้เพื่อนร่วมทริปช่วยจ่าย
 * สมาชิกจ่ายส่วนของตัวเองในแอป หรือส่งลิงก์ /pay-share/{token} ให้คนไม่มีแอป
 */
class SplitPaymentController extends Controller
{
    use ApiResponse;
    use ResolvesTransferDatetime;

    public function __construct(
        private SplitPaymentService $splitPayments,
    ) {}

    /**
     * ภาพรวมการแบ่งจ่ายของการจอง — เจ้าของหรือสมาชิก active
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($ref);

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูข้อมูลการจองนี้', 403);
        }

        return $this->success($this->splitPayments->overview($booking, $request->user()->id));
    }

    /**
     * เจ้าของเริ่มแบ่งจ่าย — ไม่ส่ง shares มา = หารเท่าอัตโนมัติ
     */
    public function store(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'shares' => ['nullable', 'array'],
            'shares.*.passenger_id' => ['nullable', 'integer'],
            'shares.*.member_id' => ['nullable', 'integer'],
            'shares.*.label' => ['nullable', 'string', 'max:60'],
            'shares.*.amount' => ['required_with:shares', 'numeric', 'min:0.01'],
        ]);

        $booking = $this->resolveBooking($ref);

        if (! $this->isOwner($booking, $request)) {
            return $this->error('เฉพาะเจ้าของการจองเท่านั้นที่เริ่มแบ่งจ่ายได้', 403);
        }

        try {
            $this->splitPayments->setup($booking, $validated['shares'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->splitPayments->overview($booking->fresh(), $request->user()->id),
            'เริ่มแบ่งจ่ายสำเร็จ',
            201,
        );
    }

    /**
     * เจ้าของแก้ยอด/ผู้รับผิดชอบของส่วนที่ยังไม่ถูกชำระ
     */
    public function update(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'shares' => ['required', 'array', 'min:1'],
            'shares.*.id' => ['nullable', 'integer'],
            'shares.*.passenger_id' => ['nullable', 'integer'],
            'shares.*.member_id' => ['nullable', 'integer'],
            'shares.*.label' => ['nullable', 'string', 'max:60'],
            'shares.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $booking = $this->resolveBooking($ref);

        if (! $this->isOwner($booking, $request)) {
            return $this->error('เฉพาะเจ้าของการจองเท่านั้นที่แก้ไขการแบ่งจ่ายได้', 403);
        }

        try {
            $this->splitPayments->updateShares($booking, $validated['shares']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->splitPayments->overview($booking->fresh(), $request->user()->id),
            'แก้ไขการแบ่งจ่ายสำเร็จ',
        );
    }

    /**
     * เจ้าของยกเลิกการแบ่งจ่าย — ส่วนที่จ่ายแล้วคงไว้ ยอดที่เหลือกลับสู่ flow ปกติ
     */
    public function destroy(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($ref);

        if (! $this->isOwner($booking, $request)) {
            return $this->error('เฉพาะเจ้าของการจองเท่านั้นที่ยกเลิกการแบ่งจ่ายได้', 403);
        }

        $this->splitPayments->cancel($booking);

        return $this->success(null, 'ยกเลิกการแบ่งจ่ายแล้ว');
    }

    /**
     * สมาชิกชำระส่วนแบ่งจากในแอป — แนบสลิปเหมือน flow ชำระเงินปกติ
     * เจ้าของและสมาชิก active จ่ายแทนส่วนไหนก็ได้ (เงินเข้าการจองเดียวกัน)
     */
    public function pay(Request $request, string $ref, int $shareId): JsonResponse
    {
        $request->validate([
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
        ]);

        $booking = $this->resolveBooking($ref);

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('คุณไม่มีสิทธิ์ชำระเงินของการจองนี้', 403);
        }

        $share = BookingSplitShare::where('booking_id', $booking->id)
            ->whereKey($shareId)
            ->first();

        if (! $share) {
            return $this->error('ไม่พบส่วนแบ่งที่ต้องการชำระ', 404);
        }

        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
        }

        try {
            $share = $this->splitPayments->payShare(
                $share,
                $request->input('payment_method', 'promptpay'),
                $slipPath,
                $this->resolveTransferDatetime($request),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'share_id' => $share->id,
            'amount' => (float) $share->amount,
            'split' => $this->splitPayments->overview($booking->fresh(), $request->user()->id),
        ], 'ชำระส่วนของคุณสำเร็จ');
    }

    /**
     * เจ้าของกดเตือนสมาชิกที่ยังไม่จ่าย (push ผ่าน FCM)
     */
    public function remind(Request $request, string $ref, int $shareId): JsonResponse
    {
        $booking = $this->resolveBooking($ref);

        if (! $this->isOwner($booking, $request)) {
            return $this->error('เฉพาะเจ้าของการจองเท่านั้นที่ส่งเตือนได้', 403);
        }

        try {
            $this->splitPayments->remind($booking, $shareId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'ส่งเตือนแล้ว');
    }

    private function resolveBooking(string $ref): Booking
    {
        return Booking::where('booking_ref', $ref)
            ->with(['schedule.trip'])
            ->firstOrFail();
    }

    private function isOwner(Booking $booking, Request $request): bool
    {
        return $booking->user_id === $request->user()->id;
    }
}
