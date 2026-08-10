<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSplitShare;
use App\Models\InstallmentPayment;
use App\Models\Payment;
use App\Models\SmartNotification;
use App\Models\User;
use App\Services\Beam\BeamClient;
use App\Services\Beam\BeamException;
use App\Support\PaymentGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ตัวกลางระหว่างการจองกับ Beam
 *
 * สองหน้าที่เท่านั้น:
 *   startCharge()  ออก QR/ลิงก์จ่ายหนึ่งใบ แล้วจดไว้ในตาราง payments ว่ารออยู่
 *   settle()       เงินเข้าแล้ว — ทำให้เกิดผลเหมือนกับตอนลูกค้าอัปสลิปเป๊ะๆ
 *
 * ยอดมาจาก PaymentQuote/ตารางต้นทางเสมอ ไม่เคยเชื่อยอดที่ client หรือ Beam ส่งมา
 * (Beam บอกได้แค่ว่า "charge ใบนี้จ่ายแล้ว" ไม่ใช่ว่า "ควรจ่ายเท่าไร")
 */
class BeamPaymentService
{
    /** purpose ที่เกิดบนการจองที่ยัง pending — ที่นั่งยังไม่ยืนยัน. */
    private const PENDING_BOOKING_PURPOSES = [
        Payment::PURPOSE_FULL,
        Payment::PURPOSE_DEPOSIT,
        Payment::PURPOSE_SPLIT,
        Payment::PURPOSE_INSTALLMENT,
    ];

    public function __construct(
        private BeamClient $beam,
        private BookingSettlementService $settlement,
        private InstallmentPaymentService $installmentPaymentService,
        private BalancePaymentService $balancePaymentService,
        private SplitPaymentService $splitPaymentService,
    ) {}

    /** ใช้ Beam อยู่ไหม — ต้องทั้งเปิดสวิตช์ และมี credential ครบ. */
    public function enabled(): bool
    {
        return PaymentGateway::isBeam();
    }

    /**
     * ออกใบชำระเงินหนึ่งใบ
     *
     * @param  array{installment_count?: int|null, purpose_id?: int|null, device_type?: string|null, user_id?: int|null}  $opts
     *
     * @throws PaymentNotAvailableException รอบนี้จ่ายแบบนี้ไม่ได้ (422)
     * @throws BeamException Beam ล่ม/ตอบ error — ผู้เรียกควรตกกลับไปทางโอนเอง
     */
    public function startCharge(Booking $booking, string $purpose, string $methodType, array $opts = []): Payment
    {
        if (! in_array($methodType, (array) config('payment.beam.methods'), true)) {
            throw new PaymentNotAvailableException('ยังไม่รองรับช่องทางการชำระเงินนี้');
        }

        $amount = $this->amountFor($booking, $purpose, $opts);

        if ($amount <= 0) {
            throw new PaymentNotAvailableException('การจองนี้ไม่มียอดที่ต้องชำระ');
        }

        // เขียนเจตนาลงฐานข้อมูลตั้งแต่ตอนออก QR ไม่ใช่ตอนเงินเข้า เพราะ webhook ไม่มี
        // ทางรู้ว่าลูกค้าเลือกผ่อนกี่งวด — ข้อมูลนั้นอยู่แค่ในคำขอที่ออก QR เท่านั้น
        if (in_array($purpose, self::PENDING_BOOKING_PURPOSES, true)) {
            DB::transaction(fn () => $this->settlement->record($booking, $purpose, [
                'installment_count' => $opts['installment_count'] ?? null,
                'payment_method' => 'beam',
            ]));
        }

        $expiresAt = $this->expiryFor($booking, $purpose);

        // จดแถวก่อนยิง Beam เพื่อให้ได้ id ไปทำ reference_id ที่ไม่มีวันซ้ำ — และเพื่อ
        // ให้มีร่องรอยไว้ตามต่อ แม้ Beam จะตอบกลับมาไม่ถึง (timeout กลางทาง)
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'purpose' => $purpose,
            'purpose_id' => $opts['purpose_id'] ?? null,
            'provider' => 'beam',
            'reference_id' => 'tmp-'.uniqid('', true),
            'amount' => $amount,
            'currency' => 'THB',
            'status' => Payment::STATUS_PENDING,
            'payment_method_type' => $methodType,
            'user_id' => $opts['user_id'] ?? $booking->user_id,
            'expires_at' => $expiresAt,
        ]);

        $payment->update(['reference_id' => $booking->booking_ref.'-'.$payment->id]);

        try {
            $response = $this->beam->createCharge($this->chargePayload($payment, $methodType, $expiresAt, $opts));
        } catch (BeamException $e) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_code' => 'create_failed',
                'raw_response' => ['error' => $e->getMessage(), 'body' => $e->body],
            ]);

            throw $e;
        }

        $payment->update([
            'provider_charge_id' => $response['chargeId'] ?? null,
            'raw_response' => $response,
            // Beam กำหนดวันหมดอายุ QR เองได้ ยึดของ Beam ถ้ามี เพราะฝั่งนั้นคือคนตัดจริง
            'expires_at' => $this->parseExpiry(data_get($response, 'encodedImage.expiry')) ?? $expiresAt,
        ]);

        return $payment->fresh();
    }

    /**
     * คืนใบที่ยังใช้ได้อยู่ ถ้าไม่มีค่อยออกใบใหม่
     *
     * สำหรับหน้าที่สร้าง charge ตอน "โหลดหน้า" (ลิงก์สาธารณะใน Blade) — ลูกค้ากด
     * refresh สามครั้งต้องไม่กลายเป็น charge สามใบค้างอยู่ที่ Beam ให้ reconcile
     * ไล่ตามเปล่าๆ ส่วนหน้าที่ผู้ใช้ "กดปุ่ม" ขอเอง ใช้ startCharge() ตรงๆ ได้เลย
     *
     * ตัดใบที่เหลืออายุไม่ถึงนาทีทิ้ง — ยื่น QR ที่กำลังจะหมดอายุให้ลูกค้าคือการหลอกกัน
     *
     * @param  array{installment_count?: int|null, purpose_id?: int|null, device_type?: string|null, user_id?: int|null}  $opts
     *
     * @throws PaymentNotAvailableException|BeamException
     */
    public function ensureCharge(Booking $booking, string $purpose, string $methodType, array $opts = []): Payment
    {
        $existing = Payment::where('booking_id', $booking->id)
            ->where('purpose', $purpose)
            ->where('purpose_id', $opts['purpose_id'] ?? null)
            ->where('payment_method_type', $methodType)
            ->where('status', Payment::STATUS_PENDING)
            ->where('expires_at', '>', now()->addMinute())
            ->latest('id')
            ->first();

        return $existing ?? $this->startCharge($booking, $purpose, $methodType, $opts);
    }

    /**
     * เงินเข้าแล้ว — ยืนยันตามชนิดของการจ่าย
     *
     * idempotent: ยิงซ้ำกี่ครั้งก็ทำงานครั้งเดียว เพราะ Beam retry ถึง 10 ครั้ง
     * และ reconcile ก็อาจมาถึงพร้อมกับ webhook ได้
     *
     * @param  array<string, mixed>  $payload
     */
    public function settle(Payment $payment, array $payload = []): void
    {
        // กันสองเส้นทาง (webhook + reconcile) เข้ามาพร้อมกันด้วย lock ที่ระดับแถว
        $claimed = DB::transaction(function () use ($payment, $payload): bool {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === Payment::STATUS_SUCCEEDED) {
                return false;
            }

            $locked->update([
                'status' => Payment::STATUS_SUCCEEDED,
                'succeeded_at' => now(),
                'raw_webhook' => $payload ?: $locked->raw_webhook,
            ]);

            return true;
        });

        if (! $claimed) {
            Log::info('Beam settle skipped — already settled', ['payment_id' => $payment->id]);

            return;
        }

        $payment = $payment->fresh();
        $booking = $payment->booking()->with(['schedule', 'seats'])->first();

        if (! $booking) {
            Log::error('Beam settle has no booking', ['payment_id' => $payment->id]);

            return;
        }

        // เงินเข้าหลังที่นั่งถูกคืนไปแล้ว — ห้ามยืนยันย้อนหลัง (ที่นั่งอาจมีคนอื่นจองไปแล้ว)
        // ต้องมีคนไปคืนเงินให้ลูกค้าแทน
        if ($booking->status === 'cancelled') {
            $this->flagOrphanPayment($payment, $booking);

            return;
        }

        match ($payment->purpose) {
            Payment::PURPOSE_FULL,
            Payment::PURPOSE_DEPOSIT,
            Payment::PURPOSE_SPLIT,
            Payment::PURPOSE_INSTALLMENT => $this->settleInitialPayment($payment, $booking),
            Payment::PURPOSE_INSTALLMENT_DUE => $this->settleInstallmentDue($payment, $booking),
            Payment::PURPOSE_BALANCE => $this->settleBalance($payment, $booking),
            Payment::PURPOSE_SPLIT_SHARE => $this->settleSplitShare($payment, $booking),
            default => Log::error('Beam settle got an unknown purpose', [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose,
            ]),
        };
    }

    /**
     * charge ล้มเหลว — ปล่อยที่นั่งไว้ตามเดิม ให้ลูกค้ากดออก QR ใหม่ได้
     *
     * @param  array<string, mixed>  $payload
     */
    public function markFailed(Payment $payment, array $payload = []): void
    {
        if ($payment->isSettled()) {
            return;
        }

        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'failure_code' => $payload['failureCode'] ?? null,
            'raw_webhook' => $payload ?: $payment->raw_webhook,
        ]);
    }

    /**
     * ถาม Beam ตรงๆ ว่า charge ใบนี้จบยังไง — ใช้ตอน reconcile เมื่อ webhook ไม่มา
     */
    public function syncFromProvider(Payment $payment): string
    {
        if ($payment->isSettled()) {
            return Payment::STATUS_SUCCEEDED;
        }

        if (! $payment->provider_charge_id) {
            // ยิงไม่ถึง Beam ตั้งแต่แรก ไม่มีอะไรให้ถาม
            $payment->update(['status' => Payment::STATUS_EXPIRED]);

            return Payment::STATUS_EXPIRED;
        }

        $charge = $this->beam->getCharge($payment->provider_charge_id);
        $status = strtoupper((string) ($charge['status'] ?? ''));

        if ($status === 'SUCCEEDED') {
            $this->settle($payment, $charge);

            return Payment::STATUS_SUCCEEDED;
        }

        if ($status === 'FAILED') {
            $this->markFailed($payment, $charge);

            return Payment::STATUS_FAILED;
        }

        // ยังค้างอยู่จริงๆ — ถ้า QR หมดอายุแล้วก็ปิดแถวทิ้ง ลูกค้าออกใบใหม่ได้
        if ($payment->isExpired()) {
            $payment->update(['status' => Payment::STATUS_EXPIRED, 'raw_webhook' => $charge]);

            return Payment::STATUS_EXPIRED;
        }

        return Payment::STATUS_PENDING;
    }

    // ── settle helpers ───────────────────────────────────────────────

    /**
     * การจ่ายครั้งแรกของการจอง (เต็ม/มัดจำ/แบ่งจ่าย/งวดแรก) — ที่นั่งยืนยันตรงนี้
     *
     * record() ถูกเรียกไปแล้วตอนออก QR แถวงวด/ส่วนแบ่งจึงมีอยู่แล้ว เหลือแค่ยืนยัน
     */
    private function settleInitialPayment(Payment $payment, Booking $booking): void
    {
        if ($booking->status === 'confirmed') {
            Log::info('Beam settle: booking already confirmed', ['booking_ref' => $booking->booking_ref]);

            return;
        }

        $this->settlement->confirm(
            $booking,
            $payment->purpose,
            $this->paymentMethodLabel($payment),
            $payment->reference_id,
            (float) $payment->amount,
        );

        $this->settlement->announce($booking, $payment->purpose);
    }

    private function settleInstallmentDue(Payment $payment, Booking $booking): void
    {
        $installment = InstallmentPayment::where('booking_id', $booking->id)
            ->whereKey($payment->purpose_id)
            ->first();

        if (! $installment || $installment->status === 'paid') {
            return;
        }

        $this->installmentPaymentService->recordPayment(
            $booking,
            $installment,
            $this->paymentMethodLabel($payment),
        );
    }

    private function settleBalance(Payment $payment, Booking $booking): void
    {
        if ($booking->balance_paid_at !== null) {
            return;
        }

        $this->balancePaymentService->recordPayment($booking, $this->paymentMethodLabel($payment));
    }

    private function settleSplitShare(Payment $payment, Booking $booking): void
    {
        $share = BookingSplitShare::find($payment->purpose_id);

        if (! $share || $share->isPaid()) {
            return;
        }

        $this->splitPaymentService->payShare($share, $this->paymentMethodLabel($payment));
    }

    /**
     * เงินเข้าแต่ที่นั่งถูกคืนไปแล้ว — ไม่มีทางแก้อัตโนมัติที่ปลอดภัย ต้องมีคนเข้ามาดู
     */
    private function flagOrphanPayment(Payment $payment, Booking $booking): void
    {
        Log::error('Beam payment landed on a cancelled booking — refund required', [
            'payment_id' => $payment->id,
            'booking_ref' => $booking->booking_ref,
            'amount' => $payment->amount,
        ]);

        $payment->update(['failure_code' => 'booking_cancelled']);

        $amount = number_format((float) $payment->amount, 2);

        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($booking, $amount) {
                SmartNotification::send(
                    $admin->id,
                    'payment_needs_refund',
                    'รับเงินหลังการจองถูกยกเลิก',
                    "เลขการจอง {$booking->booking_ref} ถูกยกเลิกไปแล้ว แต่มีเงิน {$amount} บาทเข้ามาหลังจากนั้น กรุณาตรวจสอบและคืนเงินให้ลูกค้า",
                    ['booking_ref' => $booking->booking_ref, 'route' => 'admin.bookings'],
                );
            });
        } catch (\Exception $e) {
            Log::warning('flagOrphanPayment notification failed — '.$e->getMessage());
        }
    }

    // ── charge helpers ───────────────────────────────────────────────

    /**
     * @param  array{installment_count?: int|null, purpose_id?: int|null}  $opts
     */
    private function amountFor(Booking $booking, string $purpose, array $opts): float
    {
        return match ($purpose) {
            Payment::PURPOSE_FULL,
            Payment::PURPOSE_DEPOSIT,
            Payment::PURPOSE_SPLIT,
            Payment::PURPOSE_INSTALLMENT => $this->initialAmount($booking, $purpose, $opts),
            Payment::PURPOSE_INSTALLMENT_DUE => $this->installmentDueAmount($booking, $opts['purpose_id'] ?? null),
            Payment::PURPOSE_BALANCE => $this->balanceAmount($booking),
            Payment::PURPOSE_SPLIT_SHARE => $this->shareAmount($opts['purpose_id'] ?? null),
            default => throw new PaymentNotAvailableException('รูปแบบการชำระเงินไม่ถูกต้อง'),
        };
    }

    /**
     * @param  array{installment_count?: int|null}  $opts
     */
    private function initialAmount(Booking $booking, string $purpose, array $opts): float
    {
        if ($booking->status !== 'pending') {
            throw new PaymentNotAvailableException('การจองนี้ชำระเงินไปแล้ว หรือถูกยกเลิกไปแล้ว');
        }

        return $this->settlement->quote($booking, $purpose, [
            'installment_count' => $opts['installment_count'] ?? null,
        ]);
    }

    private function installmentDueAmount(Booking $booking, ?int $installmentId): float
    {
        $installment = InstallmentPayment::where('booking_id', $booking->id)
            ->whereKey($installmentId)
            ->first();

        if (! $installment) {
            throw new PaymentNotAvailableException('ไม่พบงวดที่ต้องชำระ');
        }

        if ($installment->status === 'paid') {
            throw new PaymentNotAvailableException('งวดนี้ถูกชำระไปแล้ว');
        }

        return round((float) $installment->amount, 2);
    }

    private function balanceAmount(Booking $booking): float
    {
        if ($booking->balance_paid_at !== null) {
            throw new PaymentNotAvailableException('ยอดส่วนที่เหลือถูกชำระไปแล้ว');
        }

        return round((float) $booking->balance_amount, 2);
    }

    private function shareAmount(?int $shareId): float
    {
        $share = BookingSplitShare::find($shareId);

        if (! $share) {
            throw new PaymentNotAvailableException('ไม่พบส่วนแบ่งที่ต้องชำระ');
        }

        if ($share->isPaid()) {
            throw new PaymentNotAvailableException('ส่วนแบ่งนี้ถูกชำระไปแล้ว');
        }

        return round((float) $share->amount, 2);
    }

    /**
     * QR อยู่ได้นานแค่ไหน
     *
     * บนการจองที่ยัง pending ต้องไม่เกินเวลาที่ที่นั่งจะถูกคืน มิฉะนั้นลูกค้าจะสแกน QR
     * ที่ยังไม่หมดอายุ จ่ายเงิน แล้วพบว่าที่นั่งหายไปแล้ว
     */
    private function expiryFor(Booking $booking, string $purpose): Carbon
    {
        $ttl = max(1, (int) config('payment.beam.qr_ttl_minutes'));
        $expiry = now()->addMinutes($ttl);

        if (! in_array($purpose, self::PENDING_BOOKING_PURPOSES, true)) {
            return $expiry;
        }

        // เผื่อเวลาให้ webhook เดินทาง 1 นาที ก่อนที่ timer จะคืนที่นั่ง
        $seatDeadline = $booking->created_at
            ->copy()
            ->addMinutes(Booking::PENDING_TTL_MINUTES)
            ->subMinute();

        return $expiry->lt($seatDeadline) ? $expiry : $seatDeadline;
    }

    /**
     * @param  array{device_type?: string|null}  $opts
     * @return array<string, mixed>
     */
    private function chargePayload(Payment $payment, string $methodType, Carbon $expiresAt, array $opts): array
    {
        $paymentMethod = ['paymentMethodType' => $methodType];

        // แต่ละช่องทางมี sub-object ของตัวเอง ตามสเปกของ Beam
        $paymentMethod += match ($methodType) {
            'QR_PROMPT_PAY' => ['qrPromptPay' => ['expiryTime' => $expiresAt->toIso8601ZuluString()]],
            'KRUNGSRI_APP' => ['krungsri' => (object) []],
            'BANGKOK_BANK_APP' => ['bbl' => (object) []],
            'KPLUS' => ['kplus' => (object) []],
            'SCB_EASY' => ['scbEasy' => (object) []],
            default => [],
        };

        $payload = [
            'amount' => $payment->amountInSatang(),
            'currency' => 'THB',
            'referenceId' => $payment->reference_id,
            'returnUrl' => rtrim((string) config('payment.beam.return_url'), '/').'?payment='.$payment->id,
            'paymentMethod' => $paymentMethod,
        ];

        // ช่องทางที่เด้งออกไปแอปธนาคารต้องรู้ว่าเครื่องเป็น iOS หรือ Android
        if ($methodType !== 'QR_PROMPT_PAY') {
            $payload['deviceType'] = strtoupper($opts['device_type'] ?? 'ANDROID') === 'IOS' ? 'IOS' : 'ANDROID';
        }

        return $payload;
    }

    private function parseExpiry(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ค่าที่ลงคอลัมน์ payment_method — ต้องแยกออกจาก 'promptpay' ของทางโอนเอง
     * เพื่อให้ดูย้อนหลังได้ว่าใบไหนมาจากเกตเวย์
     */
    private function paymentMethodLabel(Payment $payment): string
    {
        return 'beam_'.strtolower((string) $payment->payment_method_type);
    }
}
