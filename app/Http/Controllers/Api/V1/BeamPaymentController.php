<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSplitShare;
use App\Models\Payment;
use App\Services\Beam\BeamException;
use App\Services\BeamPaymentService;
use App\Services\PaymentNotAvailableException;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ออก QR / ลิงก์จ่ายผ่าน Beam แล้วให้ client ตามสถานะเอง
 *
 * เมื่อจ่ายสำเร็จ ผู้ที่ยืนยันการจองคือ webhook ไม่ใช่ endpoint พวกนี้ — client แค่
 * "เห็น" ผลลัพธ์ผ่าน status() หรือผ่าน broadcast PaymentConfirmed ที่มีอยู่แล้ว
 */
class BeamPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BeamPaymentService $beamPayments,
    ) {}

    /**
     * POST /payments/beam/charge
     */
    public function charge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_ref' => ['required', 'string', 'exists:bookings,booking_ref'],
            'purpose' => ['required', 'in:full,deposit,split,installment,installment_due,balance,split_share'],
            'payment_method_type' => ['nullable', 'string', 'max:40'],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:6'],
            // แถวปลายทางของ purpose ที่ชี้เฉพาะเจาะจง: ส่วนแบ่งของเพื่อน หรือ งวดที่ 2+
            'share_id' => ['nullable', 'integer'],
            'installment_id' => ['nullable', 'integer'],
            'device_type' => ['nullable', 'in:IOS,ANDROID,ios,android'],
        ]);

        if (! $this->beamPayments->enabled()) {
            return $this->error('ระบบชำระเงินอัตโนมัติยังไม่เปิดใช้งาน กรุณาโอนและแนบสลิปตามปกติ', 503);
        }

        $booking = Booking::where('booking_ref', $validated['booking_ref'])
            ->with(['schedule', 'seats'])
            ->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('คุณไม่มีสิทธิ์ชำระเงินสำหรับการจองนี้', 403);
        }

        $purpose = $validated['purpose'];

        if ($purpose === Payment::PURPOSE_SPLIT_SHARE && ! $this->canPayShare($booking, $validated['share_id'] ?? null)) {
            return $this->error('ไม่พบส่วนแบ่งที่ต้องชำระในการจองนี้', 422);
        }

        $purposeId = match ($purpose) {
            Payment::PURPOSE_SPLIT_SHARE => $validated['share_id'] ?? null,
            Payment::PURPOSE_INSTALLMENT_DUE => $validated['installment_id'] ?? null,
            default => null,
        };

        try {
            $payment = $this->beamPayments->startCharge(
                $booking,
                $purpose,
                $validated['payment_method_type'] ?? 'QR_PROMPT_PAY',
                [
                    'installment_count' => $validated['installment_count'] ?? null,
                    'purpose_id' => $purposeId,
                    'device_type' => $validated['device_type'] ?? null,
                    'user_id' => $request->user()->id,
                ],
            );
        } catch (PaymentNotAvailableException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (BeamException $e) {
            Log::error('Beam charge could not be created', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);

            // เกตเวย์มีปัญหา ไม่ใช่ลูกค้า — บอกให้ไปทางโอนเอง+แนบสลิปแทน
            return $this->error('ระบบชำระเงินขัดข้องชั่วคราว กรุณาโอนและแนบสลิปแทน', 502);
        }

        return $this->success($this->present($payment), 'สร้างรายการชำระเงินสำเร็จ', 201);
    }

    /**
     * GET /payments/beam/{payment}
     *
     * ไว้ให้ client poll เผื่อ websocket หลุด — ตัว settle ทำโดย webhook เท่านั้น
     *
     * ตอบเฉพาะสถานะของ "ใบชำระเงินใบนี้" ไม่แนบสถานะการจองมาด้วย เพราะเคยมีบั๊กที่
     * client เห็น booking.status = confirmed แล้วสรุปเองว่าจ่ายแล้ว — ทั้งที่ยอดคงเหลือ
     * งวดที่ 2+ และส่วนแบ่งกลุ่ม จ่ายบนการจองที่ confirmed อยู่ก่อนหน้าเสมอ
     */
    public function status(Request $request, Payment $payment): JsonResponse
    {
        $booking = $payment->booking;

        if (! $booking || ! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูรายการชำระเงินนี้', 403);
        }

        return $this->success($this->present($payment));
    }

    private function canPayShare(Booking $booking, ?int $shareId): bool
    {
        return $shareId !== null
            && BookingSplitShare::whereKey($shareId)->where('booking_id', $booking->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Payment $payment): array
    {
        $raw = $payment->raw_response ?? [];

        return [
            'payment_id' => $payment->id,
            'charge_id' => $payment->provider_charge_id,
            'status' => $payment->status,
            'purpose' => $payment->purpose,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'payment_method_type' => $payment->payment_method_type,
            'action_required' => $raw['actionRequired'] ?? null,
            // PNG ของ QR — client แสดงตรงๆ ได้เลย ไม่ต้องประกอบ payload เอง
            'qr_image_base64' => data_get($raw, 'encodedImage.imageBase64Encoded'),
            'qr_raw_data' => data_get($raw, 'encodedImage.rawData'),
            'redirect_url' => data_get($raw, 'redirect.redirectUrl'),
            'expires_at' => $payment->expires_at?->toISOString(),
        ];
    }
}
