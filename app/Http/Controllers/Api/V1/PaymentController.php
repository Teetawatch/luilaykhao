<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentConfirmed;
use App\Events\SeatBooked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeRequest;
use App\Http\Resources\BookingResource;
use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmartNotification;
use App\Services\BalancePaymentService;
use App\Services\BookingService;
use App\Services\InstallmentPaymentService;
use App\Services\MailService;
use App\Services\SlipOcrService;
use App\Services\SmsService;
use App\Services\SplitPaymentService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use App\Traits\ResolvesTransferDatetime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    use ApiResponse;
    use ResolvesTransferDatetime;

    public function __construct(
        private BookingService $bookingService,
        private MailService $mailService,
        private SmsService $smsService,
        private InstallmentPaymentService $installmentPaymentService,
        private BalancePaymentService $balancePaymentService,
        private SplitPaymentService $splitPaymentService,
    ) {}

    public function charge(ChargeRequest $request): JsonResponse
    {
        try {
            $booking = Booking::where('booking_ref', $request->booking_ref)
                ->where('status', 'pending')
                ->with(['schedule', 'seats'])
                ->firstOrFail();

            $paymentType = $request->input('payment_type', 'full');
            $paymentMethod = $request->input('payment_method', 'promptpay');
            $transferDt = $this->resolveTransferDatetime($request);

            // Store slip image
            $slipPath = null;
            if ($request->hasFile('slip_image')) {
                $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
            }

            // ── Installment payment ──────────────────────────────────
            if ($paymentType === 'installment') {
                $schedule = $booking->schedule;

                if (! $schedule->installment_enabled) {
                    return $this->error('รอบเดินทางนี้ไม่รองรับการผ่อนชำระ', 422);
                }

                $installmentCount = (int) ($request->input('installment_count') ?? $schedule->installment_count);
                $maxAllowed = (int) $schedule->installment_count;
                if ($installmentCount < 2 || $installmentCount > min($maxAllowed, 6)) {
                    return $this->error("จำนวนงวดต้องอยู่ระหว่าง 2-{$maxAllowed} งวด", 422);
                }
                $installmentIntervalDays = (int) $schedule->installment_interval_days;
                $totalAmount = (float) $booking->total_amount;
                $perInstallment = round($totalAmount / $installmentCount, 2);

                DB::transaction(function () use (
                    $booking, $installmentCount, $installmentIntervalDays,
                    $perInstallment, $totalAmount, $paymentMethod, $slipPath, $transferDt
                ) {
                    $paymentRef = 'PAY-INST-'.strtoupper(uniqid());
                    $now = now();

                    $booking->update([
                        'payment_type' => 'installment',
                        'installment_count' => $installmentCount,
                        'installment_interval_days' => $installmentIntervalDays,
                        'payment_method' => $paymentMethod,
                        'payment_ref' => $paymentRef,
                        'slip_path' => $slipPath,
                        'transfer_datetime' => $transferDt,
                        'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
                    ]);

                    for ($i = 1; $i <= $installmentCount; $i++) {
                        $dueDate = $now->copy()->addDays(($i - 1) * $installmentIntervalDays);
                        $amount = ($i === $installmentCount)
                            ? round($totalAmount - ($perInstallment * ($installmentCount - 1)), 2)
                            : $perInstallment;

                        InstallmentPayment::create([
                            'booking_id' => $booking->id,
                            'installment_no' => $i,
                            'amount' => $amount,
                            'due_date' => $dueDate->toDateString(),
                            'status' => $i === 1 ? 'paid' : 'pending',
                            'payment_method' => $i === 1 ? $paymentMethod : null,
                            'payment_ref' => $i === 1 ? $paymentRef : null,
                            'paid_at' => $i === 1 ? $now : null,
                            'slip_path' => $i === 1 ? $slipPath : null,
                            'transfer_datetime' => $i === 1 ? $transferDt : null,
                        ]);
                    }

                    // For installment, paid_amount at this step is only the first installment
                    $this->bookingService->confirmBooking($booking->fresh(), $paymentMethod, $paymentRef, $perInstallment);
                });

                if ($slipPath) {
                    $firstInstallment = $booking->fresh()->installmentPayments()->where('installment_no', 1)->first();
                    if ($firstInstallment) {
                        VerifySlipJob::dispatch('installment', $firstInstallment->id, $slipPath, $perInstallment);
                    }
                }

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
                    Log::warning('Broadcast failed: '.$e->getMessage());
                }

                // Send payment confirmation email (installment)
                $this->mailService->sendPaymentConfirmedEmail($booking, 'installment');
                $this->smsService->sendPaymentConfirmed($booking, 'installment');
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
                    'status' => 'confirmed',
                    'booking' => new BookingResource($booking),
                ], 'ชำระงวดแรกสำเร็จ กรุณาชำระงวดถัดไปตามกำหนด');
            }

            // ── Split payment (จ่ายเต็มแบบแบ่งจ่ายกลุ่ม) ─────────────
            // เจ้าของชำระ "ส่วนของตัวเอง" ตอนนี้ ที่นั่งยืนยันทันที
            // ยอดที่เหลือแบ่งให้เพื่อนร่วมทริปช่วยจ่ายผ่านแอป/ลิงก์
            // ใช้กลไก balance ของมัดจำ (deposit_amount = ส่วนเจ้าของ)
            if ($paymentType === 'split') {
                $schedule = $booking->schedule;
                $passengerCount = $booking->passengers()->count();

                if ($booking->is_join_trip) {
                    return $this->error('การจองแบบจอยทริปไม่รองรับการแบ่งจ่าย', 422);
                }

                if ($passengerCount < 2) {
                    return $this->error('การแบ่งจ่ายต้องมีผู้เดินทางอย่างน้อย 2 คน', 422);
                }

                $totalAmount = (float) $booking->total_amount;
                $ownerShare = round($totalAmount / $passengerCount, 2);
                $balanceAmount = round($totalAmount - $ownerShare, 2);

                $departureDate = $schedule->departure_date;
                $balanceDueAt = $departureDate
                    ? CarbonImmutable::parse($departureDate)->subDays(15)->startOfDay()
                    : null;

                $paymentRef = 'PAY-SPLIT-'.strtoupper(uniqid());

                DB::transaction(function () use (
                    $booking, $ownerShare, $balanceAmount, $balanceDueAt,
                    $paymentMethod, $paymentRef, $slipPath, $transferDt, $passengerCount
                ) {
                    $booking->update([
                        'payment_type' => 'deposit',
                        'deposit_amount' => $ownerShare,
                        'balance_amount' => $balanceAmount,
                        'balance_due_at' => $balanceDueAt,
                        'payment_method' => $paymentMethod,
                        'payment_ref' => $paymentRef,
                        'slip_path' => $slipPath,
                        'transfer_datetime' => $transferDt,
                        'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
                    ]);

                    $this->bookingService->confirmBooking(
                        $booking->fresh(),
                        $paymentMethod,
                        $paymentRef,
                        $ownerShare,
                    );

                    $this->splitPaymentService->createSharesForRemainder(
                        $booking->fresh(),
                        $balanceAmount,
                        $passengerCount - 1,
                    );
                });

                if ($slipPath) {
                    VerifySlipJob::dispatch('booking', $booking->id, $slipPath, $ownerShare);
                }

                $booking = $booking->fresh()->load(['seats', 'schedule.trip', 'passengers', 'splitShares']);

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
                    Log::warning('Broadcast failed: '.$e->getMessage());
                }

                $this->mailService->sendDepositPaidEmail($booking);

                $shareCount = $passengerCount - 1;
                SmartNotification::send(
                    $booking->user_id,
                    'split_started',
                    'รับชำระส่วนของคุณแล้ว',
                    "รับชำระส่วนของคุณสำหรับเลขการจอง {$booking->booking_ref} แล้ว แบ่งยอดที่เหลือให้เพื่อนอีก {$shareCount} คน — เชิญเพื่อนเข้าการจองหรือส่งลิงก์ชำระเงินได้เลย",
                    [
                        'booking_ref' => $booking->booking_ref,
                        'route' => 'booking',
                    ],
                );

                return $this->success([
                    'status' => 'confirmed',
                    'booking' => new BookingResource($booking),
                ], 'ชำระส่วนของคุณสำเร็จ ส่งลิงก์ให้เพื่อนช่วยจ่ายส่วนที่เหลือได้เลย');
            }

            // ── Deposit payment ──────────────────────────────────────
            if ($paymentType === 'deposit') {
                $schedule = $booking->schedule;

                if ($booking->is_join_trip || ! $schedule->deposit_enabled) {
                    return $this->error('รอบเดินทางนี้ไม่รองรับการจ่ายมัดจำ', 422);
                }

                $totalAmount = (float) $booking->total_amount;
                $passengerCount = $booking->passengers()->count() ?: 1;
                $depositAmount = $schedule->resolveDepositAmount($totalAmount, $passengerCount);
                if ($depositAmount === null) {
                    return $this->error('ผู้ดูแลระบบยังไม่ได้กำหนดยอดมัดจำสำหรับรอบเดินทางนี้', 422);
                }
                if ($depositAmount >= $totalAmount) {
                    return $this->error('ยอดมัดจำต้องน้อยกว่ายอดรวม กรุณาเลือกชำระเต็มจำนวน', 422);
                }

                $balanceAmount = round($totalAmount - $depositAmount, 2);
                $departureDate = $schedule->departure_date;
                $balanceDueAt = $departureDate
                    ? CarbonImmutable::parse($departureDate)->subDays(15)->startOfDay()
                    : null;

                $paymentRef = 'PAY-DEP-'.strtoupper(uniqid());

                DB::transaction(function () use (
                    $booking, $depositAmount, $balanceAmount, $balanceDueAt,
                    $paymentMethod, $paymentRef, $slipPath, $transferDt
                ) {
                    $booking->update([
                        'payment_type' => 'deposit',
                        'deposit_amount' => $depositAmount,
                        'balance_amount' => $balanceAmount,
                        'balance_due_at' => $balanceDueAt,
                        'payment_method' => $paymentMethod,
                        'payment_ref' => $paymentRef,
                        'slip_path' => $slipPath,
                        'transfer_datetime' => $transferDt,
                        'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
                    ]);

                    $this->bookingService->confirmBooking(
                        $booking->fresh(),
                        $paymentMethod,
                        $paymentRef,
                        $depositAmount,
                    );
                });

                if ($slipPath) {
                    VerifySlipJob::dispatch('booking', $booking->id, $slipPath, $depositAmount);
                }

                $booking = $booking->fresh()->load(['seats', 'schedule.trip', 'passengers']);

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
                    Log::warning('Broadcast failed: '.$e->getMessage());
                }

                $this->mailService->sendDepositPaidEmail($booking);
                $this->smsService->sendDepositPaid($booking);

                $balanceDueText = $balanceDueAt ? $balanceDueAt->format('d/m/Y') : '-';
                SmartNotification::send(
                    $booking->user_id,
                    'deposit_paid',
                    'รับชำระเงินมัดจำแล้ว',
                    "รับชำระมัดจำเลขการจอง {$booking->booking_ref} แล้ว กรุณาชำระยอดส่วนที่เหลือภายในวันที่ {$balanceDueText}",
                    [
                        'booking_ref' => $booking->booking_ref,
                        'route' => 'booking',
                    ],
                );

                return $this->success([
                    'status' => 'confirmed',
                    'booking' => new BookingResource($booking),
                ], 'ชำระเงินมัดจำสำเร็จ กรุณาชำระยอดส่วนที่เหลือก่อนเดินทาง 15 วัน');
            }

            // ── Full payment ─────────────────────────────────────────
            $paymentRef = 'PAY-'.strtoupper(uniqid());

            $booking = $this->bookingService->confirmBooking(
                $booking,
                $paymentMethod,
                $paymentRef,
            );

            $booking->update([
                'payment_type' => 'full',
                'slip_path' => $slipPath,
                'transfer_datetime' => $transferDt,
                'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
            ]);

            if ($slipPath) {
                VerifySlipJob::dispatch('booking', $booking->id, $slipPath, (float) $booking->total_amount);
            }

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
                Log::warning('Broadcast failed: '.$e->getMessage());
            }

            // Send payment confirmation email (full)
            $this->mailService->sendPaymentConfirmedEmail($booking, 'full');
            $this->smsService->sendPaymentConfirmed($booking, 'full');
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
                'status' => 'confirmed',
                'booking' => new BookingResource($booking),
            ], 'ชำระเงินสำเร็จ');
        } catch (ValidationException $e) {
            return $this->error($e->validator->errors()->first(), 422);
        } catch (ModelNotFoundException $e) {
            return $this->error('ไม่พบข้อมูลการจอง หรือสถานะการจองไม่ถูกต้อง', 404);
        } catch (\Exception $e) {
            Log::error('Payment processing error: '.$e->getMessage(), [
                'booking_ref' => $request->booking_ref,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('เกิดข้อผิดพลาดในการประมวลผลการชำระเงิน: '.$e->getMessage(), 500);
        }
    }

    public function chargeInstallment(Request $request): JsonResponse
    {
        $request->validate([
            'booking_ref' => ['required', 'string'],
            'installment_no' => ['required', 'integer', 'min:2'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
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

        if (! $installment) {
            return $this->error('ไม่พบงวดที่ต้องชำระ หรือชำระแล้ว', 422);
        }

        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
        }

        $this->installmentPaymentService->recordPayment(
            $booking,
            $installment,
            $request->input('payment_method', 'promptpay'),
            $slipPath,
            $this->resolveTransferDatetime($request),
        );

        return $this->success([
            'installment_no' => $installment->installment_no,
            'amount' => $installment->amount,
        ], "ชำระงวดที่ {$installment->installment_no} สำเร็จ");
    }

    public function chargeBalance(Request $request): JsonResponse
    {
        $request->validate([
            'booking_ref' => ['required', 'string'],
            'payment_method' => ['nullable', 'in:promptpay,mobile_banking'],
            'slip_image' => ['nullable', 'image', 'max:5120'],
            'transfer_date' => ['nullable', 'date'],
            'transfer_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
        ]);

        $booking = Booking::where('booking_ref', $request->booking_ref)
            ->where('payment_type', 'deposit')
            ->whereNull('balance_paid_at')
            ->firstOrFail();

        if ((float) $booking->balance_amount <= 0) {
            return $this->error('การจองนี้ไม่มียอดส่วนที่เหลือที่ต้องชำระ', 422);
        }

        $slipPath = null;
        if ($request->hasFile('slip_image')) {
            $slipPath = $request->file('slip_image')->store('slips/'.date('Y/m'), MediaDisk::slipDisk());
        }

        $this->balancePaymentService->recordPayment(
            $booking,
            $request->input('payment_method', 'promptpay'),
            $slipPath,
            $this->resolveTransferDatetime($request),
        );

        $booking = $booking->fresh()->load(['seats', 'schedule.trip', 'passengers']);

        return $this->success([
            'status' => 'confirmed',
            'booking' => new BookingResource($booking),
        ], 'ชำระยอดส่วนที่เหลือสำเร็จ');
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
            'booking_ref' => $booking->booking_ref,
            'status' => $booking->status,
            'payment_type' => $booking->payment_type ?? 'full',
            'paid_amount' => $booking->paid_amount,
            'total_amount' => $booking->total_amount,
            'paid_at' => $booking->paid_at?->toISOString(),
            'installment_payments' => $booking->installmentPayments->map(fn ($ip) => [
                'installment_no' => $ip->installment_no,
                'amount' => $ip->amount,
                'due_date' => $ip->due_date?->toDateString(),
                'status' => $ip->status,
                'paid_at' => $ip->paid_at?->toISOString(),
            ]),
        ]);
    }

    /**
     * Read a freshly attached slip and return the transfer date/time so the
     * payment form can auto-fill them. Best-effort: a failed read is not an
     * error the customer needs to act on — they can still type it manually.
     */
    public function scanSlip(Request $request, SlipOcrService $ocrService): JsonResponse
    {
        $request->validate([
            'slip_image' => ['required', 'image', 'max:5120'],
        ]);

        $result = $ocrService->scan($request->file('slip_image'));

        if ($result === null || ($result['date'] === null && $result['time'] === null)) {
            return $this->error('ไม่สามารถอ่านวันที่และเวลาจากสลิปได้ กรุณากรอกเอง', 422);
        }

        return $this->success([
            'date' => $result['date'],
            'time' => $result['time'],
            'amount' => $result['amount'],
            'bank' => $result['bank'],
        ], 'อ่านข้อมูลจากสลิปสำเร็จ');
    }
}
