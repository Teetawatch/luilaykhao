<?php

namespace App\Services;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\SmartNotification;
use Illuminate\Support\Facades\DB;

/**
 * Logic การชำระยอดส่วนที่เหลือ (balance) ของการจองแบบมัดจำ
 * ใช้ร่วมกันระหว่าง PaymentController (ในแอป) และ PublicPaymentController (ลิงก์จากอีเมล)
 */
class BalancePaymentService
{
    public function __construct(
        private MailService $mailService,
        private SmsService $smsService,
    ) {}

    /**
     * การจองนี้ยังมียอดส่วนที่เหลือค้างชำระอยู่หรือไม่
     */
    public function hasOutstandingBalance(Booking $booking): bool
    {
        return $booking->payment_type === 'deposit'
            && $booking->balance_paid_at === null
            && (float) $booking->balance_amount > 0;
    }

    /**
     * บันทึกการชำระยอดส่วนที่เหลือ: อัปเดต booking, ตรวจสลิป, แจ้งเตือน
     */
    public function recordPayment(
        Booking $booking,
        string $paymentMethod,
        ?string $slipPath = null,
        ?string $transferDt = null,
    ): float {
        $paymentRef = 'PAY-BAL-'.strtoupper(uniqid());
        $balanceAmount = (float) $booking->balance_amount;

        DB::transaction(function () use ($booking, $paymentRef, $slipPath, $transferDt, $balanceAmount) {
            $booking->update([
                'paid_amount' => (float) $booking->paid_amount + $balanceAmount,
                'balance_paid_at' => now(),
                'balance_payment_ref' => $paymentRef,
                'balance_slip_path' => $slipPath,
                'balance_transfer_datetime' => $transferDt,
                'balance_slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
            ]);
        });

        if ($slipPath) {
            VerifySlipJob::dispatch('balance', $booking->id, $slipPath, $balanceAmount);
        }

        $fresh = $booking->fresh()->load(['seats', 'schedule.trip', 'passengers']);

        $this->mailService->sendBalancePaidEmail($fresh);
        $this->smsService->sendBalancePaid($fresh);

        if ($booking->user_id) {
            SmartNotification::send(
                $booking->user_id,
                'balance_paid',
                'รับชำระเงินส่วนที่เหลือแล้ว',
                "รับชำระยอดส่วนที่เหลือของเลขการจอง {$booking->booking_ref} ครบถ้วนแล้ว",
                [
                    'booking_ref' => $booking->booking_ref,
                    'route' => 'booking',
                ],
            );
        }

        return $balanceAmount;
    }
}
