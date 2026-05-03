<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentConfirmed;
use App\Events\SeatBooked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeRequest;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmartNotification;
use App\Services\BookingService;
use App\Services\MailService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BookingService $bookingService,
        private MailService $mailService,
    ) {}

    public function charge(ChargeRequest $request): JsonResponse
    {
        try {
            $booking = Booking::where('booking_ref', $request->booking_ref)
                ->where('status', 'pending')
                ->with(['schedule', 'seats'])
                ->firstOrFail();

            $paymentType    = $request->input('payment_type', 'full');
            $paymentMethod  = $request->input('payment_method', 'promptpay');
            $transferDt     = $this->resolveTransferDatetime($request);

            // Store slip image
            $slipPath = null;
            if ($request->hasFile('slip_image')) {
                $slipPath = $request->file('slip_image')->store('slips/' . date('Y/m'), 'public');
            }

            // ── Installment payment ──────────────────────────────────
            if ($paymentType === 'installment') {
                $schedule = $booking->schedule;

                if (!$schedule->installment_enabled) {
                    return $this->error('รอบเดินทางนี้ไม่รองรับการผ่อนชำระ', 422);
                }

                $installmentCount        = (int) ($request->input('installment_count') ?? $schedule->installment_count);
                $maxAllowed              = (int) $schedule->installment_count;
                if ($installmentCount < 2 || $installmentCount > min($maxAllowed, 6)) {
                    return $this->error("จำนวนงวดต้องอยู่ระหว่าง 2-{$maxAllowed} งวด", 422);
                }
                $installmentIntervalDays = (int) $schedule->installment_interval_days;
                $totalAmount             = (float) $booking->total_amount;
                $perInstallment          = round($totalAmount / $installmentCount, 2);

                DB::transaction(function () use (
                    $booking, $installmentCount, $installmentIntervalDays,
                    $perInstallment, $totalAmount, $paymentMethod, $slipPath, $transferDt
                ) {
                    $paymentRef = 'PAY-INST-' . strtoupper(uniqid());
                    $now = now();

                    $booking->update([
                        'payment_type'              => 'installment',
                        'installment_count'         => $installmentCount,
                        'installment_interval_days' => $installmentIntervalDays,
                        'payment_method'            => $paymentMethod,
                        'payment_ref'               => $paymentRef,
                        'slip_path'                 => $slipPath,
                        'transfer_datetime'         => $transferDt,
                    ]);

                    for ($i = 1; $i <= $installmentCount; $i++) {
                        $dueDate = $now->copy()->addDays(($i - 1) * $installmentIntervalDays);
                        $amount  = ($i === $installmentCount)
                            ? round($totalAmount - ($perInstallment * ($installmentCount - 1)), 2)
                            : $perInstallment;

                        InstallmentPayment::create([
                            'booking_id'       => $booking->id,
                            'installment_no'   => $i,
                            'amount'           => $amount,
                            'due_date'         => $dueDate->toDateString(),
                            'status'           => $i === 1 ? 'paid'      : 'pending',
                            'payment_method'   => $i === 1 ? $paymentMethod : null,
                            'payment_ref'      => $i === 1 ? $paymentRef    : null,
                            'paid_at'          => $i === 1 ? $now            : null,
                            'slip_path'        => $i === 1 ? $slipPath       : null,
                            'transfer_datetime'=> $i === 1 ? $transferDt     : null,
                        ]);
                    }

                    // For installment, paid_amount at this step is only the first installment
                    $this->bookingService->confirmBooking($booking->fresh(), $paymentMethod, $paymentRef, $perInstallment);
                });

                $booking = $booking->fresh()->load(['seats', 'installmentPayments']);

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
                    Log::warning('Broadcast failed: ' . $e->getMessage());
                }

                // Send payment confirmation email (installment)
                $this->mailService->sendPaymentConfirmedEmail($booking, 'installment');
                SmartNotification::send(
                    $booking->user_id,
                    'payment_confirmed',
                    'ยืนยันการชำระเงินแล้ว',
                    "รับชำระงวดแรกของเลขการจอง {$booking->booking_ref} แล้ว",
                    [
                        'booking_ref' => $booking->booking_ref,
                        'route' => 'booking',
                    ],
                );

                return $this->success([
                    'status'  => 'confirmed',
                    'booking' => new \App\Http\Resources\BookingResource($booking),
                ], 'ชำระงวดแรกสำเร็จ กรุณาชำระงวดถัดไปตามกำหนด');
            }

            // ── Full payment ─────────────────────────────────────────
            $paymentRef = 'PAY-' . strtoupper(uniqid());

            $booking = $this->bookingService->confirmBooking(
                $booking,
                $paymentMethod,
                $paymentRef,
            );

            $booking->update([
                'payment_type'      => 'full',
                'slip_path'         => $slipPath,
                'transfer_datetime' => $transferDt,
            ]);

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
                Log::warning('Broadcast failed: ' . $e->getMessage());
            }

            // Send payment confirmation email (full)
            $this->mailService->sendPaymentConfirmedEmail($booking, 'full');
            SmartNotification::send(
                $booking->user_id,
                'payment_confirmed',
                'ยืนยันการชำระเงินแล้ว',
                "รับชำระเงินเลขการจอง {$booking->booking_ref} แล้ว ที่นั่งของคุณได้รับการยืนยัน",
                [
                    'booking_ref' => $booking->booking_ref,
                    'route' => 'booking',
                ],
            );

            return $this->success([
                'status'  => 'confirmed',
                'booking' => new \App\Http\Resources\BookingResource($booking),
            ], 'ชำระเงินสำเร็จ');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('ไม่พบข้อมูลการจอง หรือสถานะการจองไม่ถูกต้อง', 404);
        } catch (\Exception $e) {
            Log::error('Payment processing error: ' . $e->getMessage(), [
                'booking_ref' => $request->booking_ref,
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('เกิดข้อผิดพลาดในการประมวลผลการชำระเงิน: ' . $e->getMessage(), 500);
        }
    }

    public function chargeInstallment(Request $request): JsonResponse
    {
        $request->validate([
            'booking_ref'    => ['required', 'string'],
            'installment_no' => ['required', 'integer', 'min:2'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'slip_image'     => ['nullable', 'image', 'max:5120'],
            'transfer_date'  => ['nullable', 'date'],
            'transfer_time'  => ['nullable', 'string'],
        ]);

        $booking = Booking::where('booking_ref', $request->booking_ref)
            ->where('status', 'confirmed')
            ->where('payment_type', 'installment')
            ->with('installmentPayments')
            ->firstOrFail();

        $installment = $booking->installmentPayments
            ->where('installment_no', $request->installment_no)
            ->where('status', '!=', 'paid')
            ->first();

        if (!$installment) {
            return $this->error('ไม่พบงวดที่ต้องชำระ หรือชำระแล้ว', 422);
        }

        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/' . date('Y/m'), 'public');
        }

        $paymentRef = 'PAY-INST-' . strtoupper(uniqid());
        $transferDt = $this->resolveTransferDatetime($request);

        $installment->update([
            'status'            => 'paid',
            'payment_method'    => $request->input('payment_method', 'promptpay'),
            'payment_ref'       => $paymentRef,
            'paid_at'           => now(),
            'slip_path'         => $slipPath,
            'transfer_datetime' => $transferDt,
        ]);

        $totalPaid = (float) $booking->paid_amount + (float) $installment->amount;
        $booking->update(['paid_amount' => $totalPaid]);

        // Send installment payment confirmation email
        $this->mailService->sendInstallmentPaidEmail($booking->fresh(), $installment);
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

        return $this->success([
            'installment_no' => $installment->installment_no,
            'amount'         => $installment->amount,
        ], "ชำระงวดที่ {$installment->installment_no} สำเร็จ");
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('Payment webhook received', $payload);
        return $this->success(null, 'Webhook processed');
    }

    public function status(string $bookingRef): JsonResponse
    {
        $booking = Booking::where('booking_ref', $bookingRef)
            ->with('installmentPayments')
            ->firstOrFail();

        return $this->success([
            'booking_ref'          => $booking->booking_ref,
            'status'               => $booking->status,
            'payment_type'         => $booking->payment_type ?? 'full',
            'paid_amount'          => $booking->paid_amount,
            'total_amount'         => $booking->total_amount,
            'paid_at'              => $booking->paid_at?->toISOString(),
            'installment_payments' => $booking->installmentPayments->map(fn ($ip) => [
                'installment_no'   => $ip->installment_no,
                'amount'           => $ip->amount,
                'due_date'         => $ip->due_date?->toDateString(),
                'status'           => $ip->status,
                'paid_at'          => $ip->paid_at?->toISOString(),
            ]),
        ]);
    }

    private function resolveTransferDatetime(Request $request): ?string
    {
        $date = $request->input('transfer_date');
        $time = $request->input('transfer_time');
        if ($date && $time) return "{$date} {$time}:00";
        if ($date)           return "{$date} 00:00:00";
        return null;
    }
}
