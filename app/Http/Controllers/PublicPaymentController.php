<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\BalancePaymentService;
use App\Services\InstallmentPaymentService;
use App\Services\PromptPayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * หน้าชำระเงินแบบสาธารณะ เข้าถึงผ่าน payment_token จากลิงก์ในอีเมล
 * รองรับทั้งค่างวด (installment) และยอดส่วนที่เหลือ (balance ของการจองมัดจำ)
 * ลูกค้าไม่ต้องล็อกอินหรือเปิดแอป — ดู QR PromptPay แล้วแนบสลิปได้เลย
 */
class PublicPaymentController extends Controller
{
    public function __construct(
        private InstallmentPaymentService $installmentPaymentService,
        private BalancePaymentService $balancePaymentService,
        private PromptPayService $promptPayService,
    ) {}

    public function show(string $token): View
    {
        $booking = $this->resolveBooking($token);

        if ($booking->payment_type === 'deposit') {
            return $this->showBalance($booking);
        }

        return $this->showInstallment($booking);
    }

    public function pay(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'slip_image' => ['required', 'image', 'max:5120'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'transfer_datetime' => ['nullable', 'date'],
        ]);

        $booking = $this->resolveBooking($token);
        $method = $validated['payment_method'] ?? 'promptpay';
        $transferDt = ! empty($validated['transfer_datetime'])
            ? CarbonImmutable::parse($validated['transfer_datetime'])->format('Y-m-d H:i:s')
            : null;

        // ── ยอดส่วนที่เหลือ (มัดจำ) ──
        if ($booking->payment_type === 'deposit') {
            if (! $this->balancePaymentService->hasOutstandingBalance($booking)) {
                return redirect()->route('public.pay.show', $token)
                    ->with('status', 'ชำระครบแล้ว');
            }

            $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), 'public');
            $this->balancePaymentService->recordPayment($booking, $method, $slipPath, $transferDt);

            return redirect()->route('public.pay.show', $token)->with('paid_balance', true);
        }

        // ── ค่างวด ──
        $installment = $this->installmentPaymentService->nextDueInstallment($booking);
        if (! $installment) {
            return redirect()->route('public.pay.show', $token)
                ->with('status', 'ชำระครบทุกงวดแล้ว');
        }

        $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), 'public');
        $this->installmentPaymentService->recordPayment($booking, $installment, $method, $slipPath, $transferDt);

        return redirect()->route('public.pay.show', $token)
            ->with('paid_installment_no', $installment->installment_no);
    }

    private function showInstallment(Booking $booking): View
    {
        $installment = $this->installmentPaymentService->nextDueInstallment($booking);

        if (! $installment) {
            return view('payment.installment-complete', compact('booking'));
        }

        return view('payment.installment-pay', [
            'booking' => $booking,
            'installment' => $installment,
            'qrDataUri' => $this->qrFor((float) $installment->amount),
        ]);
    }

    private function showBalance(Booking $booking): View
    {
        if (! $this->balancePaymentService->hasOutstandingBalance($booking)) {
            return view('payment.balance-complete', compact('booking'));
        }

        return view('payment.balance-pay', [
            'booking' => $booking,
            'qrDataUri' => $this->qrFor((float) $booking->balance_amount),
        ]);
    }

    private function qrFor(float $amount): string
    {
        $payload = $this->promptPayService->buildPayload(
            (string) config('payment.promptpay_id'),
            $amount,
        );

        return $this->promptPayService->qrDataUri($payload);
    }

    private function resolveBooking(string $token): Booking
    {
        return Booking::where('payment_token', strtolower(trim($token)))
            ->whereIn('payment_type', ['installment', 'deposit'])
            ->with(['schedule.trip', 'user', 'passengers', 'installmentPayments'])
            ->firstOrFail();
    }
}
