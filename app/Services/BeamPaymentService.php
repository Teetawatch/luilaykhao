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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ตัวกลางระหว่างการจองกับ Beam
 *
 * สองหน้าที่เท่านั้น:
 *   startCharge()  ออก QR/ลิงก์จ่าย/หน้าชำระเงินของ Beam หนึ่งใบ แล้วจดไว้ในตาราง
 *                  payments ว่ารออยู่
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

    /** บรรทัดรายการบนหน้าชำระเงินของ Beam — ลูกค้าต้องรู้ว่ากำลังจ่ายก้อนไหน. */
    private const PURPOSE_LABELS = [
        Payment::PURPOSE_FULL => 'ชำระเต็มจำนวน',
        Payment::PURPOSE_DEPOSIT => 'ค่ามัดจำ',
        Payment::PURPOSE_SPLIT => 'ส่วนของผู้จอง (แบ่งจ่าย)',
        Payment::PURPOSE_INSTALLMENT => 'ผ่อนชำระ งวดแรก',
        Payment::PURPOSE_INSTALLMENT_DUE => 'ค่างวด',
        Payment::PURPOSE_BALANCE => 'ยอดคงเหลือ',
        Payment::PURPOSE_SPLIT_SHARE => 'ส่วนแบ่งค่าทริป',
        Payment::PURPOSE_EXTRA => 'ยอดเพิ่มเติม',
    ];

    /** สถานะลิงก์ที่แปลว่า "จบแล้ว ไม่มีเงินเข้า" — ปิดแถวได้. */
    private const DEAD_LINK_STATUSES = ['EXPIRED', 'DISABLED'];

    public function __construct(
        private BeamClient $beam,
        private BookingSettlementService $settlement,
        private InstallmentPaymentService $installmentPaymentService,
        private BalancePaymentService $balancePaymentService,
        private SplitPaymentService $splitPaymentService,
        private ExtraPaymentService $extraPaymentService,
    ) {}

    /** ใช้ Beam อยู่ไหม — ต้องทั้งเปิดสวิตช์ และมี credential ครบ. */
    public function enabled(): bool
    {
        return PaymentGateway::isBeam();
    }

    /**
     * ออกใบชำระเงินหนึ่งใบ
     *
     * @param  array{installment_count?: int|null, purpose_id?: int|null, device_type?: string|null, user_id?: int|null, back_url?: string|null}  $opts
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

        $expiresAt = $this->expiryFor($booking, $purpose, $methodType);

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
            $response = $payment->isCheckout()
                ? $this->beam->createPaymentLink($this->checkoutPayload($payment, $booking, $expiresAt, $opts['back_url'] ?? null))
                : $this->beam->createCharge($this->chargePayload($payment, $methodType, $expiresAt, $opts));
        } catch (BeamException $e) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_code' => 'create_failed',
                'raw_response' => ['error' => $e->getMessage(), 'body' => $e->body],
            ]);

            throw $e;
        }

        if ($payment->isCheckout()) {
            // charge จริงยังไม่เกิด — Beam สร้างให้ตอนลูกค้ากดจ่ายบนหน้าของเขา
            $payment->update([
                'provider_link_id' => $response['id'] ?? $response['paymentLinkId'] ?? null,
                'raw_response' => $response,
                'expires_at' => $this->resolveExpiry($response['expiresAt'] ?? null, $expiresAt),
            ]);
        } else {
            $payment->update([
                'provider_charge_id' => $response['chargeId'] ?? null,
                'raw_response' => $response,
                'expires_at' => $this->resolveExpiry(data_get($response, 'encodedImage.expiry'), $expiresAt),
            ]);
        }

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

        // ยอดเปลี่ยนไปแล้ว (เช่น แอดมินเพิ่มของระหว่างที่ QR เดิมยังไม่หมดอายุ) —
        // ใบเดิมคือยอดเก่า ต้องออกใบใหม่ ไม่งั้นลูกค้าจ่ายไม่ครบโดยไม่รู้ตัว
        if ($existing && abs((float) $existing->amount - $this->amountFor($booking, $purpose, $opts)) >= 0.01) {
            $existing = null;
        }

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
        $this->rememberCheckoutCharge($payment, $payload);

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
            Payment::PURPOSE_EXTRA => $this->settleExtra($payment, $booking),
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

        // บนหน้าชำระเงินของ Beam บัตรโดนปฏิเสธหนึ่งใบไม่ได้ปิดลิงก์ — ลูกค้าลองบัตรอื่น
        // หรือเปลี่ยนไปจ่าย e-Wallet ต่อในหน้าเดิมได้ ถ้าปิดแถวตรงนี้ หน้าจอเราจะขึ้น
        // "ไม่สำเร็จ" ทั้งที่เขากำลังจ่ายใบที่สองอยู่ จดเหตุผลไว้อย่างเดียว
        if ($payment->isCheckout()) {
            $payment->update(['failure_code' => $payload['failureCode'] ?? $payment->failure_code]);

            return;
        }

        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'failure_code' => $payload['failureCode'] ?? null,
            'raw_webhook' => $payload ?: $payment->raw_webhook,
        ]);
    }

    /**
     * ถาม Beam ให้ "คนที่นั่งดูหน้าจอรอผลอยู่ตอนนี้"
     *
     * ตาข่ายที่มีอยู่คือ ReconcileBeamChargesJob ซึ่งแตะใบหนึ่งก็ต่อเมื่อค้างมาแล้ว 10
     * นาที — เป็นเวลาที่พอสำหรับกันการจองหลุด แต่ไม่มีใครนั่งจ้องหน้าจอรอได้นานขนาดนั้น
     * ตัวนี้จึงเป็นทางลัดสำหรับหน้าจอที่ลูกค้าบอกแล้วว่า "จ่ายไปแล้วนะ"
     *
     * lock 5 วินาที/ใบ คือตัวคุมจังหวะ: หน้าจอ poll ทุก 2-3 วินาที เปิดซ้อนกันหลายแท็บ
     * ได้ และหน้า return ก็ไม่ต้องล็อกอิน ถ้าไม่คุมไว้ ใบเดียวจะยิงหา Beam ได้เป็นสิบ
     * ครั้งต่อนาที รอบที่ชนกันไม่เสียหายอะไร — ได้สถานะจากฐานข้อมูลไปตามปกติ
     *
     * ไม่ปล่อย lock ทิ้งเมื่อทำเสร็จ ให้มันหมดอายุเองตามเวลา เพราะตัว lock คือคาบเวลา
     * ไม่ใช่การกันเข้าพร้อมกัน
     */
    public function syncForWatcher(Payment $payment): Payment
    {
        $providerId = $payment->isCheckout() ? $payment->provider_link_id : $payment->provider_charge_id;

        if ($payment->status !== Payment::STATUS_PENDING || ! $providerId) {
            return $payment;
        }

        if (! Cache::lock('beam-sync:'.$payment->id, 5)->get()) {
            return $payment;
        }

        try {
            $this->syncFromProvider($payment);

            return $payment->refresh();
        } catch (BeamException $e) {
            // ถามไม่ได้รอบนี้ไม่ใช่เรื่องที่ต้องทำให้ลูกค้าเห็น error — poll รอบหน้ายังมี
            // และ webhook + ReconcileBeamChargesJob ยังเป็นตาข่ายรับอยู่ข้างหลังเสมอ
            Log::warning('Beam watcher sync could not read the charge', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return $payment;
        }
    }

    /**
     * ถาม Beam ตรงๆ ว่า charge ใบนี้จบยังไง — ใช้ตอน reconcile เมื่อ webhook ไม่มา
     */
    public function syncFromProvider(Payment $payment): string
    {
        if ($payment->isSettled()) {
            return Payment::STATUS_SUCCEEDED;
        }

        if ($payment->isCheckout()) {
            return $this->syncCheckoutFromProvider($payment);
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

    /**
     * ใบที่ส่งไปหน้าชำระเงินของ Beam — ถามที่ตัวลิงก์ ไม่ใช่ที่ charge
     *
     * ลิงก์หนึ่งมีได้หลาย charge (บัตรใบแรกถูกปฏิเสธ ใบที่สองผ่าน) และ PAID ของลิงก์คือ
     * สัญญาณเดียวที่บอกว่ามีใบไหนสักใบผ่านแล้ว
     */
    private function syncCheckoutFromProvider(Payment $payment): string
    {
        if (! $payment->provider_link_id) {
            $payment->update(['status' => Payment::STATUS_EXPIRED]);

            return Payment::STATUS_EXPIRED;
        }

        $link = $this->beam->getPaymentLink($payment->provider_link_id);
        $status = strtoupper((string) ($link['status'] ?? ''));

        if ($status === 'PAID') {
            $this->settle($payment, $link);

            return Payment::STATUS_SUCCEEDED;
        }

        // จ่ายแล้วแต่ถูกคืนเงินไปก่อนที่เราจะรู้ — ห้ามยืนยันที่นั่งด้วยเงินที่ไม่อยู่แล้ว
        if (in_array($status, ['VOIDED', 'REFUNDED'], true)) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_code' => strtolower($status),
                'raw_webhook' => $link,
            ]);

            return Payment::STATUS_FAILED;
        }

        if (in_array($status, self::DEAD_LINK_STATUSES, true)) {
            $payment->update(['status' => Payment::STATUS_EXPIRED, 'raw_webhook' => $link]);

            return Payment::STATUS_EXPIRED;
        }

        if (! $payment->isExpired()) {
            return Payment::STATUS_PENDING;
        }

        // เลยเวลาของเราแล้วแต่ลิงก์ยังเปิดอยู่ (Beam ยังไม่ทันตัด) — ปิดเองก่อนปิดแถว
        // ไม่งั้นลูกค้าที่ยังค้างหน้าบัตรไว้จะจ่ายเข้าที่นั่งที่ถูกคืนไปแล้วได้
        try {
            $this->beam->disablePaymentLink($payment->provider_link_id);
        } catch (BeamException) {
            // ปิดไม่ได้มักแปลว่าเพิ่งถูกจ่ายไปพอดี — ถามอีกรอบแทนการเดา
            $latest = $this->beam->getPaymentLink($payment->provider_link_id);

            if (strtoupper((string) ($latest['status'] ?? '')) === 'PAID') {
                $this->settle($payment, $latest);

                return Payment::STATUS_SUCCEEDED;
            }
        }

        $payment->update(['status' => Payment::STATUS_EXPIRED, 'raw_webhook' => $link]);

        return Payment::STATUS_EXPIRED;
    }

    /**
     * จด chargeId ของใบที่จ่ายผ่านหน้าชำระเงินของ Beam — ตอนออกใบเรามีแค่ id ของลิงก์
     * แต่การคืนเงินต้องใช้ chargeId และต้องรู้ว่าจ่ายมาด้วยบัตรหรือ e-Wallet
     *
     * ทำนอกตัวกัน settle ซ้ำ เพราะ payment_link.paid อาจมาถึงก่อน charge.succeeded
     * (ใบถูก settle ไปแล้ว แต่ข้อมูลชิ้นนี้ยังต้องเก็บ)
     *
     * @param  array<string, mixed>  $payload
     */
    private function rememberCheckoutCharge(Payment $payment, array $payload): void
    {
        if (! $payment->isCheckout() || empty($payload['chargeId']) || $payment->provider_charge_id) {
            return;
        }

        $payment->update([
            'provider_charge_id' => $payload['chargeId'],
            'raw_webhook' => $payload,
        ]);
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

    /**
     * ลงยอดตามที่ออก QR ไว้ ไม่ใช่ยอดค้าง ณ ตอนเงินเข้า — ระหว่างนั้นแอดมินอาจแก้
     * ยอดอีกรอบ แต่เงินที่เข้ามาจริงคือยอดบน QR (ส่วนต่างที่เหลือจะโผล่เป็นยอดค้างใหม่)
     */
    private function settleExtra(Payment $payment, Booking $booking): void
    {
        $this->extraPaymentService->recordPayment(
            $booking,
            $this->paymentMethodLabel($payment),
            (float) $payment->amount,
        );
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
            Payment::PURPOSE_EXTRA => $booking->loadMissing('installmentPayments')->extraDueAmount(),
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
    private function expiryFor(Booking $booking, string $purpose, string $methodType): Carbon
    {
        $ttl = max(1, (int) ($methodType === Payment::METHOD_CHECKOUT
            ? config('payment.beam.checkout.ttl_minutes')
            : config('payment.beam.qr_ttl_minutes')));
        $expiry = now()->addMinutes($ttl);

        if (! in_array($purpose, self::PENDING_BOOKING_PURPOSES, true)) {
            return $expiry;
        }

        // ใบที่ทีมงานกันที่นั่งไว้ให้ไม่ได้เดินนาฬิกาสิบนาที (ExpirePendingBookingsJob
        // ข้ามใบพวกนี้) เส้นตายจึงเป็น hold_until ไม่ใช่เวลาที่เปิดใบ — ไม่งั้น QR ที่
        // ออกให้ใบที่กันไว้ตั้งแต่เมื่อวานจะหมดอายุตั้งแต่วินาทีที่ออก
        $seatDeadline = $booking->isOnHold()
            ? $booking->hold_until->copy()
            : $booking->created_at
                ->copy()
                ->addMinutes(Booking::PENDING_TTL_MINUTES)
                // เผื่อเวลาให้ webhook เดินทาง 1 นาที ก่อนที่ timer จะคืนที่นั่ง
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
            'returnUrl' => $this->returnUrl($payment),
            'paymentMethod' => $paymentMethod,
        ];

        // ช่องทางที่เด้งออกไปแอปธนาคารต้องรู้ว่าเครื่องเป็น iOS หรือ Android
        if ($methodType !== 'QR_PROMPT_PAY') {
            $payload['deviceType'] = strtoupper($opts['device_type'] ?? 'ANDROID') === 'IOS' ? 'IOS' : 'ANDROID';
        }

        return $payload;
    }

    /**
     * คำขอสร้างหน้าชำระเงินของ Beam
     *
     * ยอดในรายการ = ยอดรวม (netAmount) เสมอ เป็นบรรทัดเดียว — แตกเป็นค่าทริป/ส่วนลด/
     * ของเช่า จะต้องคำนวณซ้ำกับ PaymentQuote แล้วเสี่ยงยอดไม่ตรงกันทีละสตางค์
     *
     * @return array<string, mixed>
     */
    private function checkoutPayload(Payment $payment, Booking $booking, Carbon $expiresAt, ?string $backUrl = null): array
    {
        $satang = $payment->amountInSatang();
        $tripTitle = (string) ($booking->schedule?->trip?->title ?: 'ทริปลุยเลเขา');
        $purposeLabel = self::PURPOSE_LABELS[$payment->purpose] ?? 'ค่าทริป';

        return [
            'order' => [
                'currency' => 'THB',
                'netAmount' => $satang,
                'description' => Str::limit($tripTitle.' · '.$booking->booking_ref, 120, ''),
                'referenceId' => $payment->reference_id,
                'orderItems' => [[
                    'itemName' => Str::limit($tripTitle, 120, ''),
                    'description' => $purposeLabel.' · '.$booking->booking_ref,
                    'price' => $satang,
                    'quantity' => 1,
                    'productId' => 'schedule-'.$booking->schedule_id,
                    'sku' => $booking->booking_ref,
                ]],
            ],
            'linkSettings' => $this->checkoutLinkSettings(),
            'collectDeliveryAddress' => false,
            'collectPhoneNumber' => false,
            'redirectUrl' => $this->returnUrl($payment),
            'cancelUrl' => $this->returnUrl($payment, cancelled: true, backUrl: $backUrl),
            'expiresAt' => $expiresAt->toIso8601ZuluString(),
        ];
    }

    /**
     * linkSettings ของ Beam — ทุกกลุ่มต้องส่งครบ กลุ่มที่ไม่ได้ส่ง Beam ถือว่าปิด
     *
     * @return array<string, mixed>
     */
    private function checkoutLinkSettings(): array
    {
        $groups = (array) config('payment.beam.checkout.groups');
        $settings = [];

        foreach (['card', 'qrPromptPay', 'eWallets', 'mobileBanking', 'buyNowPayLater'] as $group) {
            $settings[$group] = ['isEnabled' => (bool) ($groups[$group] ?? false)];
        }

        $months = array_map('intval', (array) config('payment.beam.checkout.installment_months'));
        $settings['cardInstallments'] = ['isEnabled' => (bool) ($groups['cardInstallments'] ?? false)];

        foreach ([3, 4, 6, 10] as $m) {
            $settings['cardInstallments']["installments{$m}m"] = ['isEnabled' => in_array($m, $months, true)];
        }

        return $settings;
    }

    /**
     * ปลายทางที่ Beam พาลูกค้ากลับมา — ทั้งหลังจ่ายผ่านแอปธนาคาร และหลังจ่าย/กดยกเลิก
     * บนหน้าชำระเงินของ Beam
     */
    private function returnUrl(Payment $payment, bool $cancelled = false, ?string $backUrl = null): string
    {
        $url = rtrim((string) config('payment.beam.return_url'), '/').'?payment='.$payment->id;

        if ($cancelled) {
            $url .= '&cancelled=1';
        }

        // หน้าที่ลูกค้ามา (ลิงก์ชำระเงินที่ไม่ต้องล็อกอิน) — PaymentReturnController รับเฉพาะ
        // ที่อยู่บนโดเมนเราเท่านั้น
        return $backUrl ? $url.'&back='.rawurlencode($backUrl) : $url;
    }

    /**
     * เวลาหมดอายุที่จะยึดจริง เมื่อ Beam ตอบกลับมาแล้ว
     *
     * Beam เป็นคนตัด QR จริง จึงยอมให้ "หด" อายุลงได้ แต่ต้องไม่ยอมให้ "ยืด" เกิน
     * ที่เราขอ เพราะเพดานของเราคือเวลาที่ที่นั่งจะถูกคืน (ดู expiryFor) — QR ที่ยัง
     * นับถอยหลังอยู่ทั้งที่ที่นั่งหลุดไปแล้ว คือการชวนลูกค้าจ่ายเงินเข้าที่นั่งที่ไม่มีอยู่
     */
    private function resolveExpiry(mixed $value, Carbon $fallback): Carbon
    {
        $parsed = $this->parseExpiry($value);

        if ($parsed === null || $parsed->isPast()) {
            return $fallback;
        }

        return $parsed->lt($fallback) ? $parsed : $fallback;
    }

    /**
     * เวลาที่ไม่มีโซนติดมาคือเวลาไทย ไม่ใช่ UTC
     *
     * เอกสารบอกว่าส่ง ISO8601 ลงท้ายด้วย Z แต่ถ้าวันไหนตอบเป็น "2026-08-10 21:29:00"
     * เปล่าๆ มา การอ่านเป็น UTC จะได้เวลาที่บวกไปอีกเจ็ดชั่วโมง แล้วหน้าเว็บจะขึ้นว่า
     * QR หมดอายุใน 449 นาที
     */
    private function parseExpiry(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value));

            return Carbon::parse($value, $hasZone ? null : 'Asia/Bangkok')->utc();
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
        // จ่ายบนหน้าของ Beam — บันทึกช่องทางที่ลูกค้าเลือกจริง (card / line_pay / ...)
        // ถ้า charge.succeeded มาถึงก่อน ไม่งั้นก็รู้แค่ว่ามาทางหน้าชำระเงิน
        if ($payment->isCheckout()) {
            $actual = data_get($payment->raw_webhook, 'paymentMethod.paymentMethodType');

            return 'beam_'.strtolower((string) ($actual ?: 'checkout'));
        }

        return 'beam_'.strtolower((string) $payment->payment_method_type);
    }
}
