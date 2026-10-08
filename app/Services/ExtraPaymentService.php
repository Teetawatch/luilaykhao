<?php

namespace App\Services;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingExtraPayment;
use App\Models\SmartNotification;
use Illuminate\Support\Facades\DB;

/**
 * รับ "ยอดเพิ่มเติม" ของใบจองที่ยืนยันแล้ว — ดู [Booking::extraDueAmount]
 *
 * ใช้ร่วมกันระหว่างทางแนบสลิป (PaymentController::chargeExtra) และ Beam
 * (BeamPaymentService::settle) — ทั้งสองทางต้องให้ผลเหมือนกันเป๊ะ
 *
 * เหมือนยอดคงเหลือของมัดจำ: ลง paid_amount ทันที แล้วให้ VerifySlipJob อ่านสลิป
 * ตามหลัง ยอดไม่ตรงค่อยเตือนแอดมิน ไม่กันลูกค้าไว้ระหว่างรอ
 */
class ExtraPaymentService
{
    /**
     * @param  float|null  $amount  ยอดที่รับจริง — Beam ส่งยอดที่ออก QR ไว้ (ระหว่างนั้น
     *                              แอดมินอาจแก้ยอดอีก) ทางสลิปไม่ส่ง = ยอดค้าง ณ ตอนนี้
     */
    public function recordPayment(
        Booking $booking,
        string $paymentMethod,
        ?float $amount = null,
        ?string $slipPath = null,
        ?string $transferDt = null,
    ): BookingExtraPayment {
        $amount = round($amount ?? $booking->extraDueAmount(), 2);

        if ($amount <= 0) {
            throw new PaymentNotAvailableException('การจองนี้ไม่มียอดเพิ่มเติมที่ต้องชำระ');
        }

        $payment = DB::transaction(function () use ($booking, $paymentMethod, $amount, $slipPath, $transferDt) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $locked->update(['paid_amount' => round((float) $locked->paid_amount + $amount, 2)]);

            return BookingExtraPayment::create([
                'booking_id' => $locked->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'payment_ref' => 'PAY-EXT-'.strtoupper(uniqid()),
                'slip_path' => $slipPath,
                'transfer_datetime' => $transferDt,
                'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
                'paid_at' => now(),
            ]);
        });

        if ($slipPath) {
            VerifySlipJob::dispatch('extra', $payment->id, $slipPath, $amount);
        }

        if ($booking->user_id) {
            SmartNotification::send(
                $booking->user_id,
                'extra_paid',
                'รับชำระยอดเพิ่มเติมแล้ว',
                'รับชำระ '.number_format($amount, 2)." บาท ของเลขการจอง {$booking->booking_ref} เรียบร้อยแล้ว",
                [
                    'booking_ref' => $booking->booking_ref,
                    'route' => 'booking',
                ],
            );
        }

        return $payment;
    }
}
