<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BalancePaymentService;
use App\Services\Beam\BeamException;
use App\Services\BeamPaymentService;
use App\Services\BookingSettlementService;
use App\Services\InstallmentPaymentService;
use App\Services\OutstandingPaymentService;
use App\Services\PaymentNotAvailableException;
use App\Services\PromptPayService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin/Operator API สำหรับติดตามและส่งลิงก์ชำระเงินให้ลูกค้าที่ยังค้างจ่าย
 */
class AdminPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OutstandingPaymentService $outstandingPaymentService,
        private BeamPaymentService $beamPayments,
        private BookingSettlementService $settlement,
        private InstallmentPaymentService $installmentPayments,
        private BalancePaymentService $balancePayments,
        private PromptPayService $promptPay,
    ) {}

    /**
     * รายการการจองที่ยังค้างชำระ (ค่างวด + ยอดส่วนที่เหลือ) พร้อมลิงก์ชำระเงิน
     */
    public function outstanding(Request $request): JsonResponse
    {
        $rows = $this->outstandingPaymentService->rows(
            $request->integer('schedule_id') ?: null,
            $request->string('search')->trim()->value() ?: null,
        );

        return $this->success([
            'count' => $rows->count(),
            'total_due' => round((float) $rows->sum('amount_due'), 2),
            'items' => $rows->all(),
        ]);
    }

    /**
     * ส่งลิงก์ชำระเงินให้การจองหนึ่งรายการ
     */
    public function sendLink(Request $request, string $ref): JsonResponse
    {
        $channels = $this->validatedChannels($request);

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        try {
            $row = $this->outstandingPaymentService->sendLink($booking, $channels);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($row, 'ส่งลิงก์ชำระเงินแล้ว');
    }

    /**
     * ส่งลิงก์ชำระเงินให้หลายรายการพร้อมกัน (ตามรอบเดินทาง หรือรายการที่เลือก)
     */
    public function sendLinksBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['nullable', 'integer'],
            'booking_refs' => ['nullable', 'array'],
            'booking_refs.*' => ['string'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,sms'],
        ]);

        $channels = $this->validatedChannels($request);

        $rows = $this->outstandingPaymentService->rows($validated['schedule_id'] ?? null);

        if (! empty($validated['booking_refs'])) {
            $refs = collect($validated['booking_refs']);
            $rows = $rows->filter(fn ($row) => $refs->contains($row['booking_ref']))->values();
        }

        $sent = [];
        $failed = [];

        foreach ($rows as $row) {
            $booking = Booking::where('booking_ref', $row['booking_ref'])->first();
            if (! $booking) {
                continue;
            }

            try {
                $this->outstandingPaymentService->sendLink($booking, $channels);
                $sent[] = $row['booking_ref'];
            } catch (\Throwable $e) {
                $failed[] = ['booking_ref' => $row['booking_ref'], 'reason' => $e->getMessage()];
            }
        }

        return $this->success([
            'sent_count' => count($sent),
            'failed_count' => count($failed),
            'sent' => $sent,
            'failed' => $failed,
        ], 'ส่งลิงก์ชำระเงินเสร็จสิ้น');
    }

    /**
     * QR ให้ลูกค้าสแกนจ่าย ณ ตอนนี้ — ทีมงานเปิดค้างไว้บนจอ หรือแคปส่งในแชท
     *
     * มีไว้เพราะการจองที่ทีมงานเปิดแทนลูกค้าไม่มีใคร "กดจ่าย" ในแอปได้: ลูกค้าคุยอยู่
     * ในไลน์ ไม่ได้ล็อกอิน และมักไม่ได้ลงแอปเลย
     *
     * ยอดและชนิดของหนี้มาจากตัวการจองเสมอ ทีมงานเลือกเองไม่ได้ ไม่งั้นจะมีวันที่
     * ใครสักคนออก QR ยอดเต็มให้ใบที่จ่ายมัดจำไปแล้ว
     */
    public function qr(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->with('schedule')->firstOrFail();

        try {
            [$purpose, $purposeId, $label] = $this->dueCharge($booking);
            $amount = $this->dueAmount($booking, $purpose, $purposeId);
        } catch (PaymentNotAvailableException $e) {
            return $this->error($e->getMessage(), 422);
        }

        if ($amount <= 0) {
            return $this->error('การจองนี้ไม่มียอดที่ต้องชำระแล้ว', 422);
        }

        if ($this->beamPayments->enabled()) {
            try {
                // ensureCharge ไม่ใช่ startCharge — ทีมงานกดปุ่มนี้ซ้ำระหว่างคุยกับลูกค้า
                // เป็นเรื่องปกติ และไม่ควรกลายเป็น charge ค้างสามใบที่ฝั่ง Beam
                $payment = $this->beamPayments->ensureCharge($booking, $purpose, 'QR_PROMPT_PAY', [
                    'purpose_id' => $purposeId,
                    'user_id' => $booking->user_id,
                ]);

                return $this->success($this->qrPayload($booking, $label, $amount, $payment));
            } catch (PaymentNotAvailableException $e) {
                return $this->error($e->getMessage(), 422);
            } catch (BeamException $e) {
                Log::error('Admin QR could not be created at Beam', [
                    'booking_ref' => $booking->booking_ref,
                    'purpose' => $purpose,
                    'error' => $e->getMessage(),
                ]);
                // เกตเวย์ล่มไม่ควรแปลว่าทีมงานเก็บเงินไม่ได้ — ตกไปที่ QR พร้อมเพย์ของเราเอง
            }
        }

        return $this->success($this->qrPayload($booking, $label, $amount, null));
    }

    /**
     * สถานะของ QR ใบที่เพิ่งออกไป — หน้าจอทีมงานถามซ้ำเป็นระยะ จะได้รู้ทันทีที่เงินเข้า
     *
     * sync=1 คือ "ลูกค้าบอกว่าโอนแล้ว" — ถาม Beam ตรงๆ แทนการรอ webhook
     */
    public function qrStatus(Request $request, string $ref, Payment $payment): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if ($payment->booking_id !== $booking->id) {
            return $this->error('รายการชำระเงินนี้ไม่ได้อยู่ในการจองนี้', 404);
        }

        if ($request->boolean('sync')) {
            $payment = $this->beamPayments->syncForWatcher($payment);
        }

        return $this->success([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'expires_at' => $payment->expires_at?->toISOString(),
            'booking_status' => $payment->booking?->fresh()?->status,
        ]);
    }

    /**
     * หนี้ก้อนถัดไปของการจองนี้คือก้อนไหน
     *
     * @return array{0: string, 1: int|null, 2: string}
     *
     * @throws PaymentNotAvailableException
     */
    private function dueCharge(Booking $booking): array
    {
        if ($booking->status === 'cancelled') {
            throw new PaymentNotAvailableException('การจองนี้ถูกยกเลิกไปแล้ว');
        }

        if ($booking->status === 'pending') {
            return match ($booking->payment_type) {
                'deposit' => [Payment::PURPOSE_DEPOSIT, null, 'ค่ามัดจำ'],
                'installment' => [Payment::PURPOSE_INSTALLMENT, null, 'ค่างวดแรก'],
                default => [Payment::PURPOSE_FULL, null, 'ยอดเต็ม'],
            };
        }

        if ($this->balancePayments->hasOutstandingBalance($booking)) {
            return [Payment::PURPOSE_BALANCE, null, 'ยอดคงเหลือ'];
        }

        $installment = $this->installmentPayments->nextDueInstallment($booking);
        if ($installment) {
            return [Payment::PURPOSE_INSTALLMENT_DUE, $installment->id, 'ค่างวดที่ '.$installment->installment_no];
        }

        throw new PaymentNotAvailableException('การจองนี้ชำระครบแล้ว');
    }

    /**
     * ยอดของหนี้ก้อนนั้น — แหล่งเดียวกับที่ Beam ใช้ เพื่อให้ QR สำรอง (พร้อมเพย์)
     * ขึ้นยอดตรงกันเป๊ะกับ QR ของเกตเวย์
     *
     * @throws PaymentNotAvailableException
     */
    private function dueAmount(Booking $booking, string $purpose, ?int $purposeId): float
    {
        return match ($purpose) {
            Payment::PURPOSE_BALANCE => round((float) $booking->balance_amount, 2),
            Payment::PURPOSE_INSTALLMENT_DUE => round(
                (float) $booking->installmentPayments()->whereKey($purposeId)->value('amount'),
                2,
            ),
            default => $this->settlement->quote($booking, $purpose),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function qrPayload(Booking $booking, string $label, float $amount, ?Payment $payment): array
    {
        $raw = $payment?->raw_response ?? [];

        return [
            'booking_ref' => $booking->booking_ref,
            'purpose_label' => $label,
            'amount' => $payment ? (float) $payment->amount : $amount,
            'provider' => $payment ? 'beam' : 'promptpay',
            'payment_id' => $payment?->id,
            'status' => $payment?->status ?? 'pending',
            // Beam ส่ง PNG มาเป็น base64, ส่วน QR ของเราเองเป็น SVG data URI — หน้าจอ
            // แสดงอันไหนก็ได้ที่ไม่ null
            'qr_image_base64' => data_get($raw, 'encodedImage.imageBase64Encoded'),
            'qr_data_uri' => $payment ? null : $this->promptPayDataUri($amount),
            'expires_at' => $payment?->expires_at?->toISOString(),
        ];
    }

    private function promptPayDataUri(float $amount): string
    {
        return $this->promptPay->qrDataUri(
            $this->promptPay->buildPayload((string) config('payment.promptpay_id'), $amount),
        );
    }

    /**
     * @return array<int, string>
     */
    private function validatedChannels(Request $request): array
    {
        $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,sms'],
        ]);

        $channels = $request->input('channels', ['email']);

        return empty($channels) ? ['email'] : array_values(array_unique($channels));
    }
}
