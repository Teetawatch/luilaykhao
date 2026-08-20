<?php

namespace App\Services;

use App\Events\PaymentConfirmed;
use App\Events\SeatBooked;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmartNotification;
use App\Support\PaymentQuote;
use App\Support\ThaiDate;
use Illuminate\Support\Facades\Log;

/**
 * สิ่งที่เกิดขึ้นกับการจอง "เมื่อรับเงินก้อนแรก" — แยกออกมาจาก PaymentController
 *
 * เดิมตรรกะนี้เขียนยาว ~350 บรรทัดอยู่ใน PaymentController::charge() ซึ่งเรียกจาก
 * ที่อื่นไม่ได้เลย พอเกตเวย์ยิง webhook มาบอกว่า "เงินเข้าแล้ว" จึงไม่มีทางทำให้
 * เกิดผลเหมือนกับตอนลูกค้าอัปสลิปได้ ต้องยกออกมาก่อนถึงจะต่อ Beam ได้
 *
 * แบ่งเป็นสี่จังหวะ เพราะสองทางเดินต้องประกอบไม่เหมือนกัน:
 *
 *   quote()    ตรวจว่าจ่ายแบบนี้ได้ไหม + ยอดเท่าไร   (ทั้งสองทาง, ก่อนสุด)
 *   record()   เขียนเจตนาลง booking/งวด/ส่วนแบ่ง       (ทั้งสองทาง, ใน transaction)
 *   confirm()  ยืนยันที่นั่ง = เงินเข้าจริงแล้ว          (สลิป: ทันที · Beam: ตอน webhook)
 *   announce() broadcast + อีเมล + SMS + แจ้งเตือน      (นอก transaction เสมอ)
 *
 * ทางสลิปเรียกครบสี่ตัวรวดเดียว ทาง Beam เรียก quote+record ตอนออก QR แล้วค่อย
 * เรียก confirm+announce ตอน charge.succeeded เข้ามา
 *
 * ยอดทุกตัวมาจาก PaymentQuote ที่เดียวเหมือนเดิม ไม่คำนวณเองซ้ำ
 */
class BookingSettlementService
{
    public function __construct(
        private BookingService $bookingService,
        private MailService $mailService,
        private SmsService $smsService,
        private SplitPaymentService $splitPaymentService,
    ) {}

    /**
     * ยอดที่ต้องจ่าย "ตอนนี้" สำหรับวิธีที่เลือก
     *
     * @param  array{installment_count?: int|null}  $opts
     *
     * @throws PaymentNotAvailableException เมื่อรอบนี้จ่ายแบบนี้ไม่ได้
     */
    public function quote(Booking $booking, string $purpose, array $opts = []): float
    {
        return match ($purpose) {
            'full' => round((float) $booking->total_amount, 2),
            'deposit' => $this->depositQuote($booking)['amount'],
            'split' => $this->splitQuote($booking)['owner_share'],
            'installment' => PaymentQuote::installmentAmounts(
                (float) $booking->total_amount,
                $this->installmentCount($booking, $opts['installment_count'] ?? null),
            )['per_amount'],
            default => throw new PaymentNotAvailableException('รูปแบบการชำระเงินไม่ถูกต้อง'),
        };
    }

    /**
     * เขียน "เจตนาจะจ่ายแบบนี้" ลงฐานข้อมูล — ยังไม่ยืนยันที่นั่ง ยังไม่แจ้งใคร
     *
     * ต้องเรียกอยู่ใน DB::transaction ของผู้เรียก
     *
     * @param  array{
     *     installment_count?: int|null,
     *     payment_method?: string,
     *     payment_ref?: string|null,
     *     slip_path?: string|null,
     *     transfer_datetime?: mixed,
     *     slip_ocr_status?: string|null,
     * }  $opts
     */
    public function record(Booking $booking, string $purpose, array $opts = []): void
    {
        $common = [
            'payment_method' => $opts['payment_method'] ?? 'promptpay',
            'payment_ref' => $opts['payment_ref'] ?? null,
            'slip_path' => $opts['slip_path'] ?? null,
            'transfer_datetime' => $opts['transfer_datetime'] ?? null,
            'slip_ocr_status' => $opts['slip_ocr_status'] ?? null,
        ];

        $this->clearAbandonedPlan($booking, $purpose);

        match ($purpose) {
            'full' => $booking->update($common + ['payment_type' => 'full']),
            'deposit' => $this->recordDeposit($booking, $common),
            'split' => $this->recordSplit($booking, $common),
            'installment' => $this->recordInstallment(
                $booking,
                $this->installmentCount($booking, $opts['installment_count'] ?? null),
                $common,
            ),
            default => throw new PaymentNotAvailableException('รูปแบบการชำระเงินไม่ถูกต้อง'),
        };
    }

    /**
     * เงินเข้าแล้ว — ยืนยันที่นั่ง
     *
     * `full` ส่ง amount = null ให้ confirmBooking ลง paid_amount เป็นยอดเต็มเหมือนเดิม
     */
    public function confirm(Booking $booking, string $purpose, string $paymentMethod, string $paymentRef, ?float $amount = null): Booking
    {
        return $this->bookingService->confirmBooking(
            $booking->fresh(),
            $paymentMethod,
            $paymentRef,
            $purpose === 'full' ? null : $amount,
        );
    }

    /**
     * บอกให้ทุกฝ่ายรู้ว่าจ่ายแล้ว — broadcast + อีเมล + SMS + แจ้งเตือนในแอป
     *
     * ต้องอยู่ "นอก" transaction เสมอ (ส่งอีเมลใน transaction แล้ว rollback = อีเมล
     * ออกไปแล้วแต่ข้อมูลหาย) และล้มเหลวเงียบๆ ได้ — เงินเข้าแล้ว ที่นั่งยืนยันแล้ว
     * อีเมลส่งไม่ออกไม่ใช่เหตุให้ล้มทั้งรายการ
     */
    public function announce(Booking $booking, string $purpose): void
    {
        $booking = $booking->fresh()->load(['seats', 'schedule.trip', 'passengers']);

        $this->broadcastConfirmed($booking);

        match ($purpose) {
            'full' => $this->announceFull($booking),
            'deposit' => $this->announceDeposit($booking),
            'split' => $this->announceSplit($booking),
            'installment' => $this->announceInstallment($booking),
            default => null,
        };
    }

    // ── quote helpers ────────────────────────────────────────────────

    /**
     * @return array{amount: float, balance: float}
     */
    private function depositQuote(Booking $booking): array
    {
        $deposit = PaymentQuote::deposit($booking);

        if (! $deposit['available']) {
            throw new PaymentNotAvailableException(match ($deposit['reason']) {
                'not_configured' => 'ผู้ดูแลระบบยังไม่ได้กำหนดยอดมัดจำสำหรับรอบเดินทางนี้',
                'exceeds_total' => 'ยอดมัดจำต้องน้อยกว่ายอดรวม กรุณาเลือกชำระเต็มจำนวน',
                default => 'รอบเดินทางนี้ไม่รองรับการจ่ายมัดจำ',
            });
        }

        return [
            'amount' => (float) $deposit['amount'],
            'balance' => (float) $deposit['balance'],
        ];
    }

    /**
     * @return array{owner_share: float, friends_total: float, passenger_count: int}
     */
    private function splitQuote(Booking $booking): array
    {
        if ($booking->is_join_trip) {
            throw new PaymentNotAvailableException('การจองแบบจอยทริปไม่รองรับการแบ่งจ่าย');
        }

        $passengerCount = $booking->passengers()->count();

        if ($passengerCount < 2) {
            throw new PaymentNotAvailableException('การแบ่งจ่ายต้องมีผู้เดินทางอย่างน้อย 2 คน');
        }

        $split = PaymentQuote::split($booking);

        return [
            'owner_share' => (float) $split['owner_share'],
            'friends_total' => (float) $split['friends_total'],
            'passenger_count' => $passengerCount,
        ];
    }

    private function installmentCount(Booking $booking, ?int $requested): int
    {
        $schedule = $booking->schedule;

        if (! $schedule?->installment_enabled) {
            throw new PaymentNotAvailableException('รอบเดินทางนี้ไม่รองรับการผ่อนชำระ');
        }

        $maxAllowed = (int) $schedule->installment_count;
        $count = (int) ($requested ?? $maxAllowed);

        if ($count < 2 || $count > min($maxAllowed, PaymentQuote::MAX_INSTALLMENT_COUNT)) {
            throw new PaymentNotAvailableException("จำนวนงวดต้องอยู่ระหว่าง 2-{$maxAllowed} งวด");
        }

        return $count;
    }

    // ── record helpers ───────────────────────────────────────────────

    /**
     * ลบร่องรอยของรูปแบบการชำระที่ลูกค้าเลิกเลือกไปแล้ว
     *
     * ลูกค้าเปลี่ยนใจกลางคันได้ (ออก QR มัดจำไว้แล้วย้อนกลับไปจ่ายเต็มจำนวน) แต่เดิม
     * record() ของแต่ละแบบเขียนเฉพาะฟิลด์ของตัวเอง ยอดมัดจำ/ยอดคงเหลือ/กำหนดชำระ
     * ของรอบก่อนจึงค้างอยู่บนการจองที่จ่ายเต็มไปแล้ว และฝั่งแอปที่อ่าน deposit_amount
     * ที่บันทึกไว้ก่อน quote ก็หยิบยอดผีนั้นไปโชว์
     *
     * มัดจำกับแบ่งจ่ายกลุ่มใช้คู่ deposit_amount + balance_amount ร่วมกัน จึงล้างพร้อมกัน
     */
    private function clearAbandonedPlan(Booking $booking, string $purpose): void
    {
        $reset = [];

        if (! in_array($purpose, ['deposit', 'split'], true)) {
            $reset += [
                'deposit_amount' => null,
                'balance_amount' => null,
                'balance_due_at' => null,
            ];
        }

        if ($purpose !== 'installment') {
            $reset += [
                'installment_count' => null,
                'installment_interval_days' => null,
            ];

            // งวดที่ตั้งไว้รอบก่อนต้องหายไปด้วย ไม่งั้นหน้าจอยังโชว์ตารางผ่อนของแผนที่ทิ้งแล้ว
            $booking->installmentPayments()->delete();
        }

        if ($purpose !== 'split') {
            $booking->splitShares()->delete();
        }

        if ($reset !== []) {
            $booking->update($reset);
        }
    }

    /**
     * @param  array<string, mixed>  $common
     */
    private function recordDeposit(Booking $booking, array $common): void
    {
        $deposit = $this->depositQuote($booking);

        $booking->update($common + [
            'payment_type' => 'deposit',
            'deposit_amount' => $deposit['amount'],
            'balance_amount' => $deposit['balance'],
            'balance_due_at' => PaymentQuote::balanceDueAt($booking->schedule),
        ]);
    }

    /**
     * แบ่งจ่ายกลุ่มยืมกลไกมัดจำมาใช้: deposit_amount = ส่วนของเจ้าของ ส่วนที่เหลือ
     * กลายเป็น balance_amount ที่ถูกหารต่อเป็น booking_split_shares ให้เพื่อน
     *
     * @param  array<string, mixed>  $common
     */
    private function recordSplit(Booking $booking, array $common): void
    {
        $split = $this->splitQuote($booking);

        $booking->update($common + [
            'payment_type' => 'deposit',
            'deposit_amount' => $split['owner_share'],
            'balance_amount' => $split['friends_total'],
            'balance_due_at' => PaymentQuote::balanceDueAt($booking->schedule),
        ]);

        // ล้างส่วนแบ่งเดิม (กรณีจ่ายซ้ำหลังโดนกัน / ออก QR ใหม่) แล้วสร้างใหม่ ไม่ให้ซ้ำ
        $booking->splitShares()->delete();

        $this->splitPaymentService->createSharesForRemainder(
            $booking->fresh(),
            $split['friends_total'],
            $split['passenger_count'] - 1,
        );
    }

    /**
     * @param  array<string, mixed>  $common
     */
    private function recordInstallment(Booking $booking, int $count, array $common): void
    {
        $schedule = $booking->schedule;
        $intervalDays = (int) $schedule->installment_interval_days;
        $totalAmount = (float) $booking->total_amount;
        $amounts = PaymentQuote::installmentAmounts($totalAmount, $count);
        $now = now();

        $booking->update($common + [
            'payment_type' => 'installment',
            'installment_count' => $count,
            'installment_interval_days' => $intervalDays,
        ]);

        // ล้างงวดเดิม (กรณีจ่ายซ้ำหลังโดนกัน / ออก QR ใหม่) แล้วสร้างใหม่ ไม่ให้ซ้ำ
        $booking->installmentPayments()->delete();

        for ($i = 1; $i <= $count; $i++) {
            InstallmentPayment::create([
                'booking_id' => $booking->id,
                'installment_no' => $i,
                'amount' => $i === $count ? $amounts['last_amount'] : $amounts['per_amount'],
                'due_date' => $now->copy()->addDays(($i - 1) * $intervalDays)->toDateString(),
                'status' => $i === 1 ? 'paid' : 'pending',
                'payment_method' => $i === 1 ? $common['payment_method'] : null,
                'payment_ref' => $i === 1 ? $common['payment_ref'] : null,
                'paid_at' => $i === 1 ? $now : null,
                'slip_path' => $i === 1 ? $common['slip_path'] : null,
                'transfer_datetime' => $i === 1 ? $common['transfer_datetime'] : null,
            ]);
        }
    }

    // ── announce helpers ─────────────────────────────────────────────

    private function broadcastConfirmed(Booking $booking): void
    {
        try {
            foreach ($booking->seats as $seat) {
                broadcast(new SeatBooked(
                    $booking->schedule_id,
                    $seat->seat_id,
                    $booking->schedule->available_seats,
                ));
            }

            broadcast(new PaymentConfirmed(
                $booking->user_id,
                $booking->booking_ref,
                'confirmed',
                $booking->seats->pluck('seat_id')->toArray(),
            ));
        } catch (\Exception $e) {
            Log::warning('Broadcast failed: '.$e->getMessage());
        }
    }

    private function announceFull(Booking $booking): void
    {
        $this->mailService->sendPaymentConfirmedEmail($booking, 'full');
        $this->smsService->sendPaymentConfirmed($booking, 'full');
        SmartNotification::send(
            $booking->user_id,
            'payment_confirmed',
            'ยืนยันการชำระเงินแล้ว',
            "รับชำระเงินเลขการจอง {$booking->booking_ref} แล้ว ที่นั่งของคุณได้รับการยืนยัน",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );
    }

    private function announceDeposit(Booking $booking): void
    {
        $this->mailService->sendDepositPaidEmail($booking);
        $this->smsService->sendDepositPaid($booking);

        $balanceDueText = ThaiDate::full($booking->balance_due_at);
        SmartNotification::send(
            $booking->user_id,
            'deposit_paid',
            'รับชำระเงินมัดจำแล้ว',
            "รับชำระมัดจำเลขการจอง {$booking->booking_ref} แล้ว กรุณาชำระยอดส่วนที่เหลือภายในวันที่ {$balanceDueText}",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );
    }

    private function announceSplit(Booking $booking): void
    {
        $this->mailService->sendDepositPaidEmail($booking);

        $shareCount = max(0, $booking->passengers()->count() - 1);
        SmartNotification::send(
            $booking->user_id,
            'split_started',
            'รับชำระส่วนของคุณแล้ว',
            "รับชำระส่วนของคุณสำหรับเลขการจอง {$booking->booking_ref} แล้ว แบ่งยอดที่เหลือให้เพื่อนอีก {$shareCount} คน — เชิญเพื่อนเข้าการจองหรือส่งลิงก์ชำระเงินได้เลย",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );
    }

    private function announceInstallment(Booking $booking): void
    {
        $this->mailService->sendPaymentConfirmedEmail($booking, 'installment');
        $this->smsService->sendPaymentConfirmed($booking, 'installment');
        SmartNotification::send(
            $booking->user_id,
            'payment_confirmed',
            'ยืนยันการชำระเงินแล้ว',
            "รับชำระงวดแรกของเลขการจอง {$booking->booking_ref} แล้ว",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );
    }
}
