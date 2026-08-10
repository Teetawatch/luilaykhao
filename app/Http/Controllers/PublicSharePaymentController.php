<?php

namespace App\Http\Controllers;

use App\Models\BookingSplitShare;
use App\Models\Payment;
use App\Services\BeamPaymentService;
use App\Services\PromptPayService;
use App\Services\SplitPaymentService;
use App\Support\MediaDisk;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * หน้าชำระ "ส่วนแบ่งกลุ่ม" แบบสาธารณะ — เพื่อนร่วมทริปที่ไม่มีแอป
 * เปิดลิงก์ /pay-share/{token} เพื่อดู QR PromptPay แล้วแนบสลิปได้เลย
 * ตาม pattern เดียวกับ /pay/{token} (PublicPaymentController)
 */
class PublicSharePaymentController extends Controller
{
    public function __construct(
        private SplitPaymentService $splitPayments,
        private PromptPayService $promptPayService,
        private BeamPaymentService $beamPayments,
    ) {}

    public function show(string $token): View
    {
        $share = $this->resolveShare($token);
        $booking = $share->booking;

        if ($share->isPaid()) {
            return view('payment.share-complete', compact('share', 'booking'));
        }

        $payload = $this->promptPayService->buildPayload(
            (string) config('payment.promptpay_id'),
            (float) $share->amount,
        );

        return view('payment.share-pay', [
            'share' => $share,
            'booking' => $booking,
            'qrDataUri' => $this->promptPayService->qrDataUri($payload),
            'beamPayment' => $this->beamChargeFor($share),
        ]);
    }

    /**
     * QR ของเกตเวย์สำหรับส่วนแบ่งนี้ — null เมื่อยังใช้วิธีโอนเอง หรือเมื่อเกตเวย์ล่ม
     *
     * ล้มแล้วต้องไม่ทำให้หน้าพัง เพื่อนที่เปิดลิงก์มาจ่ายต้องจ่ายได้เสมอ แม้จะต้อง
     * ตกกลับไปทาง QR ของเราเอง + แนบสลิป
     */
    private function beamChargeFor(BookingSplitShare $share): ?Payment
    {
        if (! $this->beamPayments->enabled() || ! $share->booking) {
            return null;
        }

        try {
            return $this->beamPayments->ensureCharge(
                $share->booking,
                Payment::PURPOSE_SPLIT_SHARE,
                'QR_PROMPT_PAY',
                ['purpose_id' => $share->id],
            );
        } catch (\Exception $e) {
            Log::error('Public share payment page could not create a Beam charge', [
                'share_id' => $share->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function pay(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'slip_image' => ['required', 'image', 'max:5120'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'transfer_datetime' => ['nullable', 'date'],
        ]);

        $share = $this->resolveShare($token);

        if ($share->isPaid()) {
            return redirect()->route('public.pay-share.show', $token)
                ->with('status', 'ส่วนนี้ชำระแล้ว');
        }

        $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
        $transferDt = ! empty($validated['transfer_datetime'])
            ? CarbonImmutable::parse($validated['transfer_datetime'])->format('Y-m-d H:i:s')
            : null;

        $this->splitPayments->payShare(
            $share,
            $validated['payment_method'] ?? 'promptpay',
            $slipPath,
            $transferDt,
        );

        return redirect()->route('public.pay-share.show', $token)->with('paid_share', true);
    }

    private function resolveShare(string $token): BookingSplitShare
    {
        return BookingSplitShare::where('pay_token', strtolower(trim($token)))
            ->with(['booking.schedule.trip', 'member.user', 'passenger'])
            ->firstOrFail();
    }
}
