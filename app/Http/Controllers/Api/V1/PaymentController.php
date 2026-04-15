<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentConfirmed;
use App\Events\SeatBooked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeRequest;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Services\BookingService;
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
    ) {}

    public function charge(ChargeRequest $request): JsonResponse
    {
        $booking = Booking::where('booking_ref', $request->booking_ref)
            ->where('status', 'pending')
            ->with(['schedule', 'seats'])
            ->firstOrFail();

        $paymentType = $request->input('payment_type', 'full');

        // ── Installment payment ──────────────────────────────────
        if ($paymentType === 'installment') {
            $schedule = $booking->schedule;

            if (!$schedule->installment_enabled) {
                return $this->error('รอบเดินทางนี้ไม่รองรับการผ่อนชำระ', 422);
            }

            $installmentCount        = (int) $schedule->installment_count;
            $installmentIntervalDays = (int) $schedule->installment_interval_days;
            $totalAmount             = (float) $booking->total_amount;
            $perInstallment          = round($totalAmount / $installmentCount, 2);

            // Validate that first-installment amount matches what frontend sent
            $expectedFirst = (float) $request->amount;
            if (abs($expectedFirst - $perInstallment) > 0.02) {
                return $this->error('จำนวนเงินงวดแรกไม่ถูกต้อง', 422);
            }

            DB::transaction(function () use ($booking, $installmentCount, $installmentIntervalDays, $perInstallment, $totalAmount, $request) {
                // Save installment meta on booking
                $booking->update([
                    'payment_type'              => 'installment',
                    'installment_count'         => $installmentCount,
                    'installment_interval_days' => $installmentIntervalDays,
                    'payment_method'            => $request->input('payment_method', 'promptpay'),
                ]);

                $chargeId = 'chrg_inst_' . uniqid();

                // Build installment schedule
                $now = now();
                for ($i = 1; $i <= $installmentCount; $i++) {
                    $dueDate = $now->copy()->addDays(($i - 1) * $installmentIntervalDays);
                    // Adjust last installment amount for rounding difference
                    $amount = ($i === $installmentCount)
                        ? round($totalAmount - ($perInstallment * ($installmentCount - 1)), 2)
                        : $perInstallment;

                    $status = 'pending';
                    $paidAt = null;
                    $ref    = null;

                    if ($i === 1) {
                        $status = 'paid';
                        $paidAt = $now;
                        $ref    = $chargeId;
                    }

                    InstallmentPayment::create([
                        'booking_id'     => $booking->id,
                        'installment_no' => $i,
                        'amount'         => $amount,
                        'due_date'       => $dueDate->toDateString(),
                        'status'         => $status,
                        'payment_method' => $i === 1 ? $request->input('payment_method', 'promptpay') : null,
                        'payment_ref'    => $ref,
                        'paid_at'        => $paidAt,
                    ]);
                }

                // Update paid_amount with first installment
                $booking->update([
                    'paid_amount' => $perInstallment,
                    'payment_ref' => $chargeId,
                ]);

                // Confirm booking (seats locked in)
                $this->bookingService->confirmBooking($booking->fresh(), 'installment', $chargeId);
            });

            $booking = $booking->fresh()->load(['seats', 'installmentPayments']);

            broadcast(new PaymentConfirmed(
                $booking->user_id,
                $booking->booking_ref,
                'confirmed',
                $booking->seats->pluck('seat_id')->toArray(),
            ));

            return $this->success([
                'status'  => 'confirmed',
                'booking' => new \App\Http\Resources\BookingResource($booking),
            ], 'ชำระงวดแรกสำเร็จ กรุณาชำระงวดถัดไปตามกำหนด');
        }

        // ── Full payment ─────────────────────────────────────────
        if ((float) $request->amount !== (float) $booking->total_amount) {
            return $this->error('จำนวนเงินไม่ตรงกับยอดจอง', 422);
        }

        $chargeId = 'chrg_test_' . uniqid();

        $booking = $this->bookingService->confirmBooking(
            $booking,
            $request->input('payment_method', 'promptpay'),
            $chargeId,
        );

        $booking->update(['payment_type' => 'full']);

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

        return $this->success([
            'charge_id' => $chargeId,
            'status'    => 'confirmed',
            'booking'   => new \App\Http\Resources\BookingResource($booking),
        ], 'ชำระเงินสำเร็จ');
    }

    public function chargeInstallment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_ref'      => ['required', 'string'],
            'installment_no'   => ['required', 'integer', 'min:2'],
            'payment_method'   => ['nullable', 'string'],
        ]);

        $booking = Booking::where('booking_ref', $validated['booking_ref'])
            ->where('status', 'confirmed')
            ->where('payment_type', 'installment')
            ->with('installmentPayments')
            ->firstOrFail();

        $installment = $booking->installmentPayments
            ->where('installment_no', $validated['installment_no'])
            ->where('status', '!=', 'paid')
            ->first();

        if (!$installment) {
            return $this->error('ไม่พบงวดที่ต้องชำระ หรือชำระแล้ว', 422);
        }

        $chargeId = 'chrg_inst_' . uniqid();
        $installment->update([
            'status'         => 'paid',
            'payment_method' => $validated['payment_method'] ?? 'promptpay',
            'payment_ref'    => $chargeId,
            'paid_at'        => now(),
        ]);

        // Update paid_amount on booking
        $totalPaid = (float) $booking->paid_amount + (float) $installment->amount;
        $booking->update(['paid_amount' => $totalPaid]);

        return $this->success([
            'charge_id'        => $chargeId,
            'installment_no'   => $installment->installment_no,
            'amount'           => $installment->amount,
        ], "ชำระงวดที่ {$installment->installment_no} สำเร็จ");
    }

    public function webhook(Request $request): JsonResponse
    {
        // In production: verify Omise webhook signature
        // $secret = config('services.omise.webhook_secret');

        $payload = $request->all();
        Log::info('Payment webhook received', $payload);

        $event = $payload['event'] ?? null;
        $chargeId = $payload['data']['id'] ?? null;

        if ($event === 'charge.complete' && $chargeId) {
            $booking = Booking::where('payment_ref', $chargeId)->first();
            if ($booking && $booking->status === 'pending') {
                $this->bookingService->confirmBooking($booking, 'credit_card', $chargeId);
            }
        }

        return $this->success(null, 'Webhook processed');
    }

    public function status(string $bookingRef): JsonResponse
    {
        $booking = Booking::where('booking_ref', $bookingRef)
            ->with('installmentPayments')
            ->firstOrFail();

        return $this->success([
            'booking_ref'        => $booking->booking_ref,
            'status'             => $booking->status,
            'payment_type'       => $booking->payment_type ?? 'full',
            'paid_amount'        => $booking->paid_amount,
            'total_amount'       => $booking->total_amount,
            'paid_at'            => $booking->paid_at?->toISOString(),
            'installment_payments' => $booking->installmentPayments->map(fn ($ip) => [
                'installment_no' => $ip->installment_no,
                'amount'         => $ip->amount,
                'due_date'       => $ip->due_date?->toDateString(),
                'status'         => $ip->status,
                'paid_at'        => $ip->paid_at?->toISOString(),
            ]),
        ]);
    }
}
