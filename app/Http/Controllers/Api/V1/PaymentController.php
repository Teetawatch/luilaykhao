<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeRequest;
use App\Http\Resources\BookingResource;
use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingSplitShare;
use App\Models\SmartNotification;
use App\Models\User;
use App\Services\BalancePaymentService;
use App\Services\BookingService;
use App\Services\BookingSettlementService;
use App\Services\InstallmentPaymentService;
use App\Services\PaymentNotAvailableException;
use App\Services\PromptPayService;
use App\Services\QrCodeService;
use App\Services\SlipOcrService;
use App\Support\MediaDisk;
use App\Support\PaymentQuote;
use App\Traits\ApiResponse;
use App\Traits\ResolvesTransferDatetime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    use ApiResponse;
    use ResolvesTransferDatetime;

    public function __construct(
        private BookingService $bookingService,
        private InstallmentPaymentService $installmentPaymentService,
        private BalancePaymentService $balancePaymentService,
        private SlipOcrService $slipOcrService,
    ) {}

    /**
     * "กันกลาง": ตรวจสลิปแบบซิงโครนัสเพื่อตัดสินใจว่าต้องส่งให้แอดมินตรวจก่อนไหม
     * คืน hold=true เฉพาะเมื่อ OCR อ่านสลิปได้จริงแล้วพบว่า "ยอดไม่ตรง" หรือสลิปเป็น
     * รายการที่ล้มเหลว เท่านั้น กรณีอ่านไม่ได้/ไม่ชัดเจน (เช่น ไม่มี API key, timeout)
     * คืน hold=false เพื่อคงพฤติกรรมเดิม — ยืนยันทันทีแล้ว flag ให้แอดมินผ่าน VerifySlipJob
     *
     * @return array{hold: bool, raw: array|null}
     */
    private function slipNeedsReview(?string $slipPath, float $expectedAmount): array
    {
        if (! $slipPath) {
            return ['hold' => false, 'raw' => null];
        }

        $result = $this->slipOcrService->verify($slipPath, $expectedAmount);
        $hold = in_array($result['reason'] ?? '', ['amount_mismatch', 'slip_status_failed'], true);

        return ['hold' => $hold, 'raw' => $result['raw'] ?? null];
    }

    /**
     * ค้าง booking ไว้ให้แอดมินตรวจสอบ — สถานะยังเป็น pending (ที่นั่งยังถูก hold และ
     * timer ยกเลิกอัตโนมัติจะข้ามให้เพราะ slip_ocr_status ถูกตั้งค่าแล้ว) ไม่ยืนยันการจอง
     * และไม่ส่งอีเมล/SMS "ยืนยันแล้ว" — แจ้งแอดมินให้ตรวจ และแจ้งลูกค้าว่ากำลังตรวจสอบ
     */
    private function holdBookingForReview(Booking $booking, ?array $ocrRaw): JsonResponse
    {
        $booking->update([
            'slip_ocr_status' => SlipOcrService::STATUS_FAILED,
            'slip_ocr_result' => $ocrRaw,
        ]);

        $this->notifySlipReviewAdmins($booking->booking_ref, 'amount_mismatch');

        SmartNotification::send(
            $booking->user_id,
            'payment_under_review',
            'กำลังตรวจสอบการชำระเงิน',
            "ได้รับสลิปของเลขการจอง {$booking->booking_ref} แล้ว ทีมงานกำลังตรวจสอบยอดโอนและจะยืนยันการจองให้โดยเร็ว",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );

        return $this->success([
            'status' => 'pending_review',
            'booking' => new BookingResource($booking->fresh()->load(['seats', 'schedule.trip', 'passengers'])),
        ], 'ได้รับสลิปแล้ว — อยู่ระหว่างตรวจสอบยอดโอน ทีมงานจะยืนยันการจองให้เร็วที่สุด');
    }

    private function notifySlipReviewAdmins(string $bookingRef, string $reason): void
    {
        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($bookingRef, $reason) {
                SmartNotification::send(
                    $admin->id,
                    'slip_ocr_failed',
                    'สลิปต้องตรวจสอบ',
                    "ยอดโอนไม่ตรงอัตโนมัติ: {$bookingRef} (สาเหตุ: {$reason}) กรุณาตรวจสอบและอนุมัติด้วยตนเอง",
                    ['booking_ref' => $bookingRef, 'route' => 'admin.bookings'],
                );
            });
        } catch (\Exception $e) {
            Log::warning('notifySlipReviewAdmins failed — '.$e->getMessage());
        }
    }

    /**
     * เตือนเมื่อยอดที่ client แสดงให้ลูกค้าโอน ไม่ตรงกับยอดที่ระบบจะเรียกเก็บจริง
     *
     * ผ่อนชำระข้ามไป เพราะจำนวนงวดเป็นตัวเลือกของลูกค้า ยอดจึงต่างกันได้ตามงวดที่เลือก
     */
    private function warnOnQuoteMismatch(Booking $booking, string $paymentType, mixed $declaredAmount): void
    {
        if ($declaredAmount === null || $paymentType === 'installment') {
            return;
        }

        $expected = match ($paymentType) {
            'deposit' => PaymentQuote::deposit($booking)['amount'],
            'split' => PaymentQuote::split($booking)['owner_share'],
            default => (float) $booking->total_amount,
        };

        if ($expected === null || abs((float) $declaredAmount - (float) $expected) <= 0.01) {
            return;
        }

        Log::warning('ยอดที่ client แจ้งมาไม่ตรงกับยอดที่ระบบเรียกเก็บ', [
            'booking_ref' => $booking->booking_ref,
            'payment_type' => $paymentType,
            'declared_amount' => (float) $declaredAmount,
            'expected_amount' => (float) $expected,
        ]);
    }

    /**
     * Slip-upload payment (วิธีเดิม): ลูกค้าโอนเองแล้วส่งรูปสลิปมา
     *
     * ตรรกะว่า "รับเงินแล้วต้องเกิดอะไรขึ้นกับการจอง" อยู่ใน BookingSettlementService
     * เพราะทาง Beam ต้องทำให้เกิดผลเหมือนกันเป๊ะตอน webhook เข้า ที่นี่เหลือแค่ส่วนที่
     * เป็นของ "ทางสลิป" จริงๆ: เก็บรูป ตรวจยอดด้วย OCR และตัดสินใจว่าจะกันไว้ให้
     * แอดมินตรวจไหม
     */
    public function charge(ChargeRequest $request, BookingSettlementService $settlement): JsonResponse
    {
        try {
            $booking = Booking::where('booking_ref', $request->booking_ref)
                ->where('status', 'pending')
                ->with(['schedule', 'seats'])
                ->firstOrFail();

            $paymentType = $request->input('payment_type', 'full');
            $paymentMethod = $request->input('payment_method', 'promptpay');
            $transferDt = $this->resolveTransferDatetime($request);

            // ยอดที่จะเรียกเก็บมาจาก PaymentQuote เสมอ ไม่เชื่อยอดที่ client ส่งมา —
            // แต่ถ้าสองค่าไม่ตรงกันแปลว่า client ไปโชว์ยอดผิดให้ลูกค้าโอน (เช่นแอป
            // เวอร์ชันเก่าที่ยังคำนวณมัดจำเอง) ต้องเห็นใน log ไม่ใช่รู้ตอนสลิปไม่ตรง
            $this->warnOnQuoteMismatch($booking, $paymentType, $request->input('amount'));

            $installmentCount = $request->input('installment_count') !== null
                ? (int) $request->input('installment_count')
                : null;

            // ยอดที่ต้องโอนตอนนี้ + ตรวจว่ารอบนี้จ่ายแบบนี้ได้จริงไหม (โยน 422 ถ้าไม่ได้)
            $dueNow = $settlement->quote($booking, $paymentType, ['installment_count' => $installmentCount]);

            // Store slip image
            $slipPath = null;
            if ($request->hasFile('slip_image')) {
                $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
            }

            $paymentRef = match ($paymentType) {
                'installment' => 'PAY-INST-'.strtoupper(uniqid()),
                'split' => 'PAY-SPLIT-'.strtoupper(uniqid()),
                'deposit' => 'PAY-DEP-'.strtoupper(uniqid()),
                default => 'PAY-'.strtoupper(uniqid()),
            };

            $recordOpts = [
                'installment_count' => $installmentCount,
                'payment_method' => $paymentMethod,
                'payment_ref' => $paymentRef,
                'slip_path' => $slipPath,
                'transfer_datetime' => $transferDt,
                'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
            ];

            // ตรวจยอดก่อน — ยอดไม่ตรงให้ค้างรอแอดมิน ไม่ยืนยันที่นั่ง
            $review = $this->slipNeedsReview($slipPath, $dueNow);

            DB::transaction(function () use ($settlement, $booking, $paymentType, $recordOpts, $review, $paymentMethod, $paymentRef, $dueNow) {
                $settlement->record($booking, $paymentType, $recordOpts);

                if (! $review['hold']) {
                    $settlement->confirm($booking, $paymentType, $paymentMethod, $paymentRef, $dueNow);
                }
            });

            if ($review['hold']) {
                return $this->holdBookingForReview($booking, $review['raw']);
            }

            if ($slipPath) {
                // งวดผ่อนตรวจสลิปที่ระดับ "งวด" ไม่ใช่ระดับการจอง
                if ($paymentType === 'installment') {
                    $firstInstallment = $booking->fresh()->installmentPayments()->where('installment_no', 1)->first();
                    if ($firstInstallment) {
                        VerifySlipJob::dispatch('installment', $firstInstallment->id, $slipPath, $dueNow);
                    }
                } else {
                    VerifySlipJob::dispatch('booking', $booking->id, $slipPath, $dueNow);
                }
            }

            $settlement->announce($booking, $paymentType);

            $booking = $booking->fresh()->load(match ($paymentType) {
                'installment' => ['seats', 'installmentPayments'],
                'split' => ['seats', 'schedule.trip', 'passengers', 'splitShares'],
                default => ['seats', 'schedule.trip', 'passengers'],
            });

            return $this->success([
                'status' => 'confirmed',
                'booking' => new BookingResource($booking),
            ], match ($paymentType) {
                'installment' => 'ชำระงวดแรกสำเร็จ กรุณาชำระงวดถัดไปตามกำหนด',
                'split' => 'ชำระส่วนของคุณสำเร็จ ส่งลิงก์ให้เพื่อนช่วยจ่ายส่วนที่เหลือได้เลย',
                'deposit' => 'ชำระเงินมัดจำสำเร็จ กรุณาชำระยอดส่วนที่เหลือก่อนเดินทาง 15 วัน',
                default => 'ชำระเงินสำเร็จ',
            });
        } catch (PaymentNotAvailableException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (ValidationException $e) {
            return $this->error($e->validator->errors()->first(), 422);
        } catch (ModelNotFoundException $e) {
            return $this->error('ไม่พบข้อมูลการจอง หรือสถานะการจองไม่ถูกต้อง', 404);
        } catch (\Exception $e) {
            Log::error('Payment processing error: '.$e->getMessage(), [
                'booking_ref' => $request->booking_ref,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('เกิดข้อผิดพลาดในการประมวลผลการชำระเงิน: '.$e->getMessage(), 500);
        }
    }

    public function chargeInstallment(Request $request): JsonResponse
    {
        $request->validate([
            'booking_ref' => ['required', 'string'],
            'installment_no' => ['required', 'integer', 'min:2'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
        ]);

        $booking = Booking::where('booking_ref', $request->booking_ref)
            ->where('status', 'confirmed')
            ->where('payment_type', 'installment')
            ->with('installmentPayments')
            ->firstOrFail();

        $installment = $booking->installmentPayments
            ->where('installment_no', $request->installment_no)
            ->where('status', '!=', 'paid')
            ->first();

        if (! $installment) {
            return $this->error('ไม่พบงวดที่ต้องชำระ หรือชำระแล้ว', 422);
        }

        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
        }

        $this->installmentPaymentService->recordPayment(
            $booking,
            $installment,
            $request->input('payment_method', 'promptpay'),
            $slipPath,
            $this->resolveTransferDatetime($request),
        );

        return $this->success([
            'installment_no' => $installment->installment_no,
            'amount' => $installment->amount,
        ], "ชำระงวดที่ {$installment->installment_no} สำเร็จ");
    }

    public function chargeBalance(Request $request): JsonResponse
    {
        $request->validate([
            'booking_ref' => ['required', 'string'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
        ]);

        $booking = Booking::where('booking_ref', $request->booking_ref)
            ->where('payment_type', 'deposit')
            ->whereNull('balance_paid_at')
            ->firstOrFail();

        if ((float) $booking->balance_amount <= 0) {
            return $this->error('การจองนี้ไม่มียอดส่วนที่เหลือที่ต้องชำระ', 422);
        }

        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
        }

        $this->balancePaymentService->recordPayment(
            $booking,
            $request->input('payment_method', 'promptpay'),
            $slipPath,
            $this->resolveTransferDatetime($request),
        );

        $booking = $booking->fresh()->load(['seats', 'schedule.trip', 'passengers']);

        return $this->success([
            'status' => 'confirmed',
            'booking' => new BookingResource($booking),
        ], 'ชำระยอดส่วนที่เหลือสำเร็จ');
    }

    /**
     * Inbound payment-gateway webhook.
     *
     * The endpoint is unauthenticated (Sanctum can't sign gateway callbacks),
     * so the sender must instead HMAC-SHA256 the raw request body with the
     * shared secret and send the hex digest in the X-Payment-Signature header.
     * Unsigned/invalid calls are rejected — previously this happily returned
     * success to anyone. Reconciliation is idempotent: replaying the same
     * "charge succeeded" event on an already-confirmed booking is a no-op.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('payment.webhook_secret');

        if (empty($secret)) {
            Log::warning('Payment webhook hit but no PAYMENT_WEBHOOK_SECRET is configured — rejecting.');

            return $this->error('Webhook not configured', 503);
        }

        $signature = (string) $request->header('X-Payment-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            Log::warning('Payment webhook rejected: invalid signature', [
                'ip' => $request->ip(),
            ]);

            return $this->error('Invalid signature', 401);
        }

        $payload = $request->all();
        $event = (string) ($payload['event'] ?? '');
        $bookingRef = $payload['booking_ref'] ?? null;
        Log::info('Payment webhook accepted', ['event' => $event, 'booking_ref' => $bookingRef]);

        if (! $bookingRef) {
            return $this->success(null, 'Acknowledged');
        }

        $booking = Booking::where('booking_ref', $bookingRef)->first();
        if (! $booking) {
            Log::warning('Payment webhook references unknown booking', ['booking_ref' => $bookingRef]);

            return $this->success(null, 'Acknowledged');
        }

        // A successful charge confirms a still-pending booking. Any other state
        // (already confirmed/cancelled) is left untouched so replays are safe.
        if (in_array($event, ['charge.complete', 'charge.succeeded', 'payment.succeeded'], true)) {
            if ($booking->status === 'pending') {
                $paymentRef = $payload['payment_ref'] ?? 'WH-'.strtoupper(uniqid());
                $this->bookingService->confirmBooking($booking, 'gateway', $paymentRef);
                Log::info('Payment webhook confirmed booking', ['booking_ref' => $bookingRef]);
            }
        }

        return $this->success(null, 'Processed');
    }

    /**
     * QR พร้อมเพย์ของยอดที่ต้องโอน "ตอนนี้"
     *
     * มีไว้เพื่อไม่ให้ client แต่ละตัวไปประกอบ EMVCo payload เอง — เว็บกับแอปทำเอง
     * มาก่อนหน้านี้และตัวเลขในนั้นก็เพี้ยนกันมาแล้วรอบหนึ่ง (ดู PaymentQuote) ยอดบน
     * QR จึงมาจากหลังบ้านเสมอ ไม่ใช่ยอดที่ client ส่งมา
     *
     * รองรับทั้งการชำระครั้งแรกของใบที่ยังรอชำระ (full/deposit/installment/split —
     * ยอดจาก BookingSettlementService::quote() ตัวเดียวกับที่ charge() เก็บเงินจริง)
     * และรายการที่จ่ายบนใบที่ยืนยันแล้ว: ยอดคงเหลือ, ค่างวดที่ 2 เป็นต้นไป และ
     * ส่วนแบ่งของเพื่อน — สามอันหลังอ่านยอดจากแถวที่บันทึกไว้ ไม่ใช่จาก quote
     */
    public function promptPayQr(
        Request $request,
        string $bookingRef,
        PromptPayService $promptPay,
        BookingSettlementService $settlement,
    ): JsonResponse {
        $validated = $request->validate([
            'purpose' => ['nullable', 'in:full,deposit,installment,split,balance,installment_due,split_share'],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:'.PaymentQuote::MAX_INSTALLMENT_COUNT],
            'installment_no' => ['nullable', 'integer', 'min:2'],
            'share_id' => ['nullable', 'integer'],
        ]);

        $booking = Booking::where('booking_ref', $bookingRef)
            ->with(['schedule', 'installmentPayments'])
            ->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูรายการชำระเงินนี้', 403);
        }

        $purpose = $validated['purpose'] ?? 'full';
        $paidOnConfirmed = in_array($purpose, ['balance', 'installment_due', 'split_share'], true);

        if (! $paidOnConfirmed && $booking->status !== 'pending') {
            return $this->error('การจองนี้ไม่ได้อยู่ระหว่างรอชำระเงินครั้งแรก', 422);
        }

        try {
            $amount = $paidOnConfirmed
                ? $this->outstandingAmount($booking, $purpose, $validated)
                : $settlement->quote($booking, $purpose, [
                    'installment_count' => $validated['installment_count'] ?? null,
                ]);
        } catch (PaymentNotAvailableException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $identifier = (string) config('payment.promptpay_id');

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'purpose' => $purpose,
            'amount' => $amount,
            'qr_data_uri' => $promptPay->qrDataUri($promptPay->buildPayload($identifier, $amount)),
            'promptpay_id' => config('payment.promptpay_id_display'),
            'merchant_name' => config('payment.merchant_name'),
            'bank_name' => config('payment.bank_name'),
            'bank_account' => config('payment.bank_account'),
            'bank_holder' => config('payment.bank_holder'),
            'support_phone' => config('payment.support_phone'),
        ]);
    }

    /**
     * ยอดของรายการที่จ่ายบนใบจองที่ยืนยันแล้ว
     *
     * อ่านจากแถวที่บันทึกไว้เท่านั้น (balance_amount / installment_payments /
     * booking_split_shares) — ยอดพวกนี้ถูกตรึงตั้งแต่ตอนตกลงแผนการชำระ การไป
     * คิดใหม่จาก quote จะได้ตัวเลขที่ไม่ตรงกับที่ระบบรอรับ
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws PaymentNotAvailableException
     */
    private function outstandingAmount(Booking $booking, string $purpose, array $validated): float
    {
        if ($purpose === 'balance') {
            if ($booking->balance_paid_at !== null || (float) $booking->balance_amount <= 0) {
                throw new PaymentNotAvailableException('การจองนี้ไม่มียอดส่วนที่เหลือที่ต้องชำระ');
            }

            return round((float) $booking->balance_amount, 2);
        }

        if ($purpose === 'installment_due') {
            $installment = $booking->installmentPayments
                ->where('installment_no', $validated['installment_no'] ?? 0)
                ->firstWhere('status', '!=', 'paid');

            if (! $installment) {
                throw new PaymentNotAvailableException('ไม่พบงวดที่ต้องชำระ หรือชำระแล้ว');
            }

            return round((float) $installment->amount, 2);
        }

        $share = BookingSplitShare::whereKey($validated['share_id'] ?? 0)
            ->where('booking_id', $booking->id)
            ->where('status', '!=', BookingSplitShare::STATUS_PAID)
            ->first();

        if (! $share) {
            throw new PaymentNotAvailableException('ไม่พบส่วนแบ่งที่ต้องชำระในการจองนี้');
        }

        return round((float) $share->amount, 2);
    }

    /**
     * QR เช็คอินของใบจอง (โค้ดเดียวกับที่ทีมงานสแกนหน้างาน)
     *
     * เว็บวาด QR เองด้วยไลบรารี npm — LIFF ไม่มี build step จึงให้เซิร์ฟเวอร์
     * วาดเป็น SVG มาให้เลย ดีกว่าลากไลบรารีจาก CDN มาเพื่อสี่เหลี่ยมรูปเดียว
     */
    public function checkInQr(Request $request, string $bookingRef, QrCodeService $qrCodes): JsonResponse
    {
        $booking = Booking::where('booking_ref', $bookingRef)->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูการจองนี้', 403);
        }

        if (blank($booking->qr_code)) {
            return $this->error('การจองนี้ยังไม่มีรหัสเช็คอิน', 422);
        }

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'code' => $booking->qr_code,
            'qr_data_uri' => $qrCodes->svgDataUri($booking->qr_code, 260),
            'checked_in' => (bool) $booking->checked_in,
            'checked_in_at' => $booking->checked_in_at?->toISOString(),
        ]);
    }

    public function status(string $bookingRef): JsonResponse
    {
        $booking = Booking::where('booking_ref', $bookingRef)
            ->with('installmentPayments')
            ->firstOrFail();

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'status' => $booking->status,
            'payment_type' => $booking->payment_type ?? 'full',
            'paid_amount' => $booking->paid_amount,
            'total_amount' => $booking->total_amount,
            'paid_at' => $booking->paid_at?->toISOString(),
            'installment_payments' => $booking->installmentPayments->map(fn ($ip) => [
                'installment_no' => $ip->installment_no,
                'amount' => $ip->amount,
                'due_date' => $ip->due_date?->toDateString(),
                'status' => $ip->status,
                'paid_at' => $ip->paid_at?->toISOString(),
            ]),
        ]);
    }

    /**
     * Read a freshly attached slip and return the transfer date/time so the
     * payment form can auto-fill them. Best-effort: a failed read is not an
     * error the customer needs to act on — they can still type it manually.
     */
    public function scanSlip(Request $request, SlipOcrService $ocrService): JsonResponse
    {
        $request->validate([
            'slip_image' => ['required', 'image', 'max:5120'],
        ]);

        $result = $ocrService->scan($request->file('slip_image'));

        if ($result === null || ($result['date'] === null && $result['time'] === null)) {
            return $this->error('ไม่สามารถอ่านวันที่และเวลาจากสลิปได้ กรุณากรอกเอง', 422);
        }

        return $this->success([
            'date' => $result['date'],
            'time' => $result['time'],
            'amount' => $result['amount'],
            'bank' => $result['bank'],
        ], 'อ่านข้อมูลจากสลิปสำเร็จ');
    }
}
