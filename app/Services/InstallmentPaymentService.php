<?php

namespace App\Services;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmartNotification;

/**
 * Logic การชำระค่างวดถัดไป ใช้ร่วมกันระหว่าง PaymentController (ในแอป)
 * และ PublicPaymentController (ลิงก์ชำระจากอีเมล)
 */
class InstallmentPaymentService
{
    public function __construct(
        private MailService $mailService,
        private SmsService $smsService,
    ) {}

    /**
     * งวดถัดไปที่ยังไม่ได้ชำระ (เรียงตามลำดับงวด) — null ถ้าจ่ายครบแล้ว
     */
    public function nextDueInstallment(Booking $booking): ?InstallmentPayment
    {
        return $booking->installmentPayments()
            ->where('status', '!=', 'paid')
            ->orderBy('installment_no')
            ->first();
    }

    /**
     * บันทึกการชำระค่างวดหนึ่งงวด: mark paid, ตรวจสลิป, อัปเดตยอดรวม, แจ้งเตือน
     */
    public function recordPayment(
        Booking $booking,
        InstallmentPayment $installment,
        string $paymentMethod,
        ?string $slipPath = null,
        ?string $transferDt = null,
    ): InstallmentPayment {
        $paymentRef = 'PAY-INST-'.strtoupper(uniqid());

        $installment->update([
            'status' => 'paid',
            'payment_method' => $paymentMethod,
            'payment_ref' => $paymentRef,
            'paid_at' => now(),
            'slip_path' => $slipPath,
            'transfer_datetime' => $transferDt,
            'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
        ]);

        if ($slipPath) {
            VerifySlipJob::dispatch('installment', $installment->id, $slipPath, (float) $installment->amount);
        }

        $totalPaid = (float) $booking->paid_amount + (float) $installment->amount;
        $booking->update(['paid_amount' => $totalPaid]);

        $fresh = $booking->fresh();

        $this->mailService->sendInstallmentPaidEmail($fresh, $installment);
        $this->smsService->sendInstallmentPaid($fresh, $installment);

        if ($booking->user_id) {
            SmartNotification::send(
                $booking->user_id,
                'installment_paid',
                'รับชำระค่างวดแล้ว',
                "รับชำระงวดที่ {$installment->installment_no} ของเลขการจอง {$booking->booking_ref} แล้ว",
                [
                    'booking_ref' => $booking->booking_ref,
                    'installment_no' => $installment->installment_no,
                    'route' => 'booking',
                ],
            );
        }

        return $installment;
    }
}
