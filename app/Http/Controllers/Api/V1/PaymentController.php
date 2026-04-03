<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentConfirmed;
use App\Events\SeatBooked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeRequest;
use App\Models\Booking;
use App\Services\BookingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->firstOrFail();

        if ((float) $request->amount !== (float) $booking->total_amount) {
            return $this->error('จำนวนเงินไม่ตรงกับยอดจอง', 422);
        }

        // In production, this would call Omise API
        // $charge = OmiseCharge::create([
        //     'amount' => $booking->total_amount * 100, // satang
        //     'currency' => 'thb',
        //     'card' => $request->omise_token,
        // ]);

        // For development/demo: simulate successful payment
        $chargeId = 'chrg_test_' . uniqid();

        $booking = $this->bookingService->confirmBooking(
            $booking,
            'credit_card',
            $chargeId,
        );

        // Broadcast events
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
            'status' => 'confirmed',
            'booking' => new \App\Http\Resources\BookingResource($booking),
        ], 'ชำระเงินสำเร็จ');
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
        $booking = Booking::where('booking_ref', $bookingRef)->firstOrFail();

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'status' => $booking->status,
            'paid_amount' => $booking->paid_amount,
            'paid_at' => $booking->paid_at?->toISOString(),
        ]);
    }
}
