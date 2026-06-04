<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\InstallmentPaymentService;
use App\Services\PromptPayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * หน้าชำระค่างวดแบบสาธารณะ เข้าถึงผ่าน payment_token จากลิงก์ในอีเมล
 * ลูกค้าไม่ต้องล็อกอินหรือเปิดแอป — ดู QR PromptPay แล้วแนบสลิปได้เลย
 */
class PublicPaymentController extends Controller
{
    public function __construct(
        private InstallmentPaymentService $installmentPaymentService,
        private PromptPayService $promptPayService,
    ) {}

    public function show(string $token): View
    {
        $booking = $this->resolveBooking($token);
        $installment = $this->installmentPaymentService->nextDueInstallment($booking);

        if (! $installment) {
            return view('payment.installment-complete', compact('booking'));
        }

        $payload = $this->promptPayService->buildPayload(
            (string) config('payment.promptpay_id'),
            (float) $installment->amount,
        );

        return view('payment.installment-pay', [
            'booking' => $booking,
            'installment' => $installment,
            'qrDataUri' => $this->promptPayService->qrDataUri($payload),
        ]);
    }

    public function pay(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'slip_image' => ['required', 'image', 'max:5120'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'transfer_datetime' => ['nullable', 'date'],
        ]);

        $booking = $this->resolveBooking($token);
        $installment = $this->installmentPaymentService->nextDueInstallment($booking);

        if (! $installment) {
            return redirect()->route('public.pay.show', $token)
                ->with('status', 'ชำระครบทุกงวดแล้ว');
        }

        $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), 'public');

        $transferDt = ! empty($validated['transfer_datetime'])
            ? CarbonImmutable::parse($validated['transfer_datetime'])->format('Y-m-d H:i:s')
            : null;

        $this->installmentPaymentService->recordPayment(
            $booking,
            $installment,
            $validated['payment_method'] ?? 'promptpay',
            $slipPath,
            $transferDt,
        );

        return redirect()->route('public.pay.show', $token)
            ->with('paid_installment_no', $installment->installment_no);
    }

    private function resolveBooking(string $token): Booking
    {
        return Booking::where('payment_token', strtolower(trim($token)))
            ->where('payment_type', 'installment')
            ->with(['schedule.trip', 'user', 'passengers', 'installmentPayments'])
            ->firstOrFail();
    }
}
