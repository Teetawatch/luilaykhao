<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\Promotion;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private SeatLockService $seatLockService,
        private MailService $mailService,
        private SmsService $smsService,
    ) {}

    public function createBooking(
        int $userId,
        int $scheduleId,
        array $passengers,
        array $seatIds = [],
        ?int $pickupPointId = null,
        ?string $pickupRegion = null,
        bool $isGroup = false,
        ?string $groupName = null,
        ?string $groupNotes = null,
        ?string $promotionCode = null,
        bool $isJoinTrip = false,
    ): Booking {
        $booking = DB::transaction(function () use ($userId, $scheduleId, $passengers, $seatIds, $pickupPointId, $pickupRegion, $isGroup, $groupName, $groupNotes, $promotionCode, $isJoinTrip) {
            $schedule = TripSchedule::with('trip')->lockForUpdate()->findOrFail($scheduleId);
            $schedule->syncBookedSeats();

            if ($isJoinTrip && !$schedule->join_trip_enabled) {
                throw new \Exception('รอบเดินทางนี้ไม่เปิดให้จองแบบ Join Trip');
            }

            $participantCount = count($passengers);

            // Join trip allows unlimited bookings — skip seat availability check
            if (!$isJoinTrip && $schedule->available_seats < $participantCount) {
                throw new \Exception('ที่นั่งไม่เพียงพอ');
            }

            // Verify seat locks if seat-based booking and NOT join trip
            if (!$isJoinTrip && !empty($seatIds)) {
                foreach ($seatIds as $seatId) {
                    if (!$this->seatLockService->isLockedByUser($scheduleId, $seatId, $userId)) {
                        throw new \Exception("ที่นั่ง {$seatId} ไม่ได้ถูกล็อคโดยคุณ");
                    }
                }
            }

            // Use join trip price if applicable
            if ($isJoinTrip) {
                $pricePerPerson = $schedule->join_trip_price ?? $schedule->effective_price;
                $pickupPoint = null;
                $pickupRegion = null; // Join trip might not need pickup region if they meet at destination? 
                // But user didn't specify. I'll keep pickup logic if they provided it.
            } else {
                $pricePerPerson = $schedule->effective_price;
                $pickupPoint = null;

                if ($pickupPointId) {
                    $pickupPoint = SchedulePickupPoint::where('id', $pickupPointId)
                        ->where('schedule_id', $scheduleId)
                        ->first();
                } elseif ($pickupRegion) {
                    $pickupPoint = SchedulePickupPoint::where('schedule_id', $scheduleId)
                        ->where('region', $pickupRegion)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first();
                }

                if ($pickupPoint) {
                    $pricePerPerson = $pickupPoint->price;
                    $pickupRegion = $pickupPoint->region; 
                }
            }

            $totalAmount = $pricePerPerson * $participantCount;
            
            // ... rest of the logic remains the same until Booking::create
            // I need to include the rest of the logic here because I'm replacing a large block.
            
            $promotionId = null;
            $discountAmount = 0;

            if ($promotionCode) {
                $promotion = Promotion::where('code', $promotionCode)->where('is_active', true)->lockForUpdate()->first();
                if ($promotion) {
                    $isValid = true;
                    if ($promotion->start_date && now()->startOfDay()->lt($promotion->start_date)) $isValid = false;
                    if ($promotion->end_date && now()->startOfDay()->gt($promotion->end_date)) $isValid = false;
                    if ($promotion->max_uses && $promotion->used_count >= $promotion->max_uses) $isValid = false;
                    if ($promotion->trip_ids && is_array($promotion->trip_ids) && !in_array($schedule->trip_id, $promotion->trip_ids)) $isValid = false;

                    if ($isValid) {
                        $promotionId = $promotion->id;
                        if ($promotion->type === 'percent') {
                            $discountAmount = ($totalAmount * $promotion->value) / 100;
                        } else {
                            $discountAmount = $promotion->value;
                        }

                        if ($discountAmount > $totalAmount) {
                            $discountAmount = $totalAmount;
                        }

                        $totalAmount -= $discountAmount;
                        $promotion->increment('used_count');
                    } else {
                        throw new \Exception('โค้ดส่วนลดไม่สามารถใช้งานได้');
                    }
                } else {
                    throw new \Exception('ไม่พบโค้ดส่วนลดนี้');
                }
            }

            $booking = Booking::create([
                'booking_ref' => Booking::generateRef(),
                'user_id' => $userId,
                'schedule_id' => $scheduleId,
                'pickup_region' => $pickupRegion,
                'pickup_point_id' => $pickupPoint?->id,
                'is_group' => $isGroup || $participantCount > 1,
                'group_name' => $groupName,
                'group_notes' => $groupNotes,
                'qr_code' => Booking::generateQrCode(),
                'status' => 'pending',
                'total_amount' => $totalAmount,
                'promotion_id' => $promotionId,
                'promotion_code' => $promotionId ? $promotionCode : null,
                'discount_amount' => $discountAmount,
                'is_join_trip' => $isJoinTrip,
            ]);

            // Create passengers
            foreach ($passengers as $passengerData) {
                BookingPassenger::create([
                    'booking_id' => $booking->id,
                    ...$passengerData,
                ]);
            }

            // Create seats if seat-based and NOT join trip
            if (!$isJoinTrip && !empty($seatIds)) {
                foreach ($seatIds as $index => $seatId) {
                    BookingSeat::create([
                        'booking_id' => $booking->id,
                        'schedule_id' => $scheduleId,
                        'seat_id' => $seatId,
                        'passenger_name' => $passengers[$index]['name'] ?? null,
                    ]);
                }
                foreach ($seatIds as $seatId) {
                    $this->seatLockService->forceUnlock($scheduleId, $seatId);
                }
            }

            if (!$isJoinTrip) {
                $schedule->increment('booked_seats', $participantCount);
            }

            $booking->load(['passengers', 'seats', 'schedule.trip']);

            return $booking;
        });

        // Send emails outside of DB transaction
        $this->mailService->sendBookingCreatedEmail($booking);
        $this->smsService->sendBookingCreated($booking);
        SmartNotification::send(
            $booking->user_id,
            'booking_created',
            'สร้างการจองสำเร็จ',
            "เลขการจอง {$booking->booking_ref} ถูกสร้างแล้ว กรุณาชำระเงินเพื่อยืนยันที่นั่ง",
            [
                'booking_ref' => $booking->booking_ref,
                'route' => 'booking',
            ],
        );

        return $booking;
    }

    public function confirmBooking(Booking $booking, string $paymentMethod, string $paymentRef, ?float $amount = null): Booking
    {
        return DB::transaction(function () use ($booking, $paymentMethod, $paymentRef, $amount) {
            $booking->update([
                'status' => 'confirmed',
                'paid_amount' => $amount ?? $booking->total_amount,
                'payment_method' => $paymentMethod,
                'payment_ref' => $paymentRef,
                'paid_at' => now(),
            ]);

            // Release seat locks
            if ($booking->seats->isNotEmpty()) {
                foreach ($booking->seats as $seat) {
                    $this->seatLockService->forceUnlock($booking->schedule_id, $seat->seat_id);
                }
            }

            return $booking->fresh(['passengers', 'seats', 'schedule.trip']);
        });
    }

    public function cancelBooking(Booking $booking, ?string $reason = null): Booking
    {
        $cancelled = DB::transaction(function () use ($booking, $reason) {
            $booking->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            // Keep the cached counter aligned with active, non-join-trip bookings.
            $schedule = $booking->schedule()->lockForUpdate()->first();
            $schedule?->syncBookedSeats();

            // Release seat locks & delete booking seats
            foreach ($booking->seats as $seat) {
                $this->seatLockService->forceUnlock($booking->schedule_id, $seat->seat_id);
            }
            $booking->seats()->delete();

            return $booking->fresh(['passengers', 'schedule.trip']);
        });

        // Send cancellation email outside of DB transaction
        $this->mailService->sendBookingCancelledEmail($cancelled, $reason);
        $this->smsService->sendBookingCancelled($cancelled, $reason);
        SmartNotification::send(
            $cancelled->user_id,
            'booking_cancelled',
            'การจองถูกยกเลิก',
            "เลขการจอง {$cancelled->booking_ref} ถูกยกเลิกแล้ว",
            [
                'booking_ref' => $cancelled->booking_ref,
                'route' => 'booking',
            ],
        );

        return $cancelled;
    }

    public function calculateRefundPercent(Booking $booking): int
    {
        $schedule = $booking->schedule;
        $daysUntilDeparture = now()->diffInDays($schedule->departure_date, false);

        if ($daysUntilDeparture >= 7) return 80;
        if ($daysUntilDeparture >= 3) return 50;
        return 0;
    }
}
