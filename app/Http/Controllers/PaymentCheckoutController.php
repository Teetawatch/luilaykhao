<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Beam\BeamException;
use App\Services\BeamPaymentService;
use App\Services\PaymentNotAvailableException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * ปุ่ม "บัตร / Apple Pay / ผ่อน / e-Wallet" บนหน้าชำระเงินแบบลิงก์ (Blade ที่ไม่ต้องล็อกอิน)
 *
 * หน้าพวกนั้นออก QR ไว้แล้วหนึ่งใบ — ใบนั้นบอกครบว่าจ่ายก้อนไหน (purpose + purpose_id)
 * ปุ่มนี้จึงแค่ออกหน้าชำระเงินของ Beam สำหรับก้อนเดียวกันแล้วพาไป ไม่ต้องรู้ token ของ
 * แต่ละหน้า
 *
 * ลิงก์ต้องเซ็นไว้ (signed route) เพราะ id ของ payments เรียงต่อกัน — ไม่งั้นใครก็ไล่
 * เลขแล้วเห็นชื่อทริป เลขการจอง และยอดของคนอื่นบนหน้าของ Beam ได้
 */
class PaymentCheckoutController extends Controller
{
    public function __invoke(Payment $payment, BeamPaymentService $beamPayments): RedirectResponse
    {
        $booking = $payment->booking()->with(['schedule.trip', 'seats'])->first();
        $back = url()->previous();

        if (! $booking || ! $beamPayments->enabled()) {
            return redirect()->to($back);
        }

        try {
            $checkout = $beamPayments->ensureCharge($booking, $payment->purpose, Payment::METHOD_CHECKOUT, [
                'purpose_id' => $payment->purpose_id,
                'user_id' => $payment->user_id,
                'back_url' => $back,
            ]);
        } catch (PaymentNotAvailableException|BeamException $e) {
            Log::warning('Public checkout link could not open a Beam payment link', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            // จ่ายไปแล้ว / เกตเวย์ล่ม — กลับหน้าเดิม ซึ่งยังมี QR ให้จ่ายต่อได้
            return redirect()->to($back);
        }

        $url = $checkout->raw_response['url'] ?? null;

        return $url ? redirect()->away($url) : redirect()->to($back);
    }
}
