<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private SeatLockService $seatLockService,
    ) {}

    public function createBooking(
        int $userId,
        int $scheduleId,
        array $passengers,
        array $seatIds = [],
        ?string $pickupRegion = null,
        bool $isGroup = false,
        ?string $groupName = null,
        ?string $groupNotes = null,
    ): Booking {
        return DB::transaction(function () use ($userId, $scheduleId, $passengers, $seatIds, $pickupRegion, $isGroup, $groupName, $groupNotes) {
            $schedule = TripSchedule::with('trip')->lockForUpdate()->findOrFail($scheduleId);

            $participantCount = count($passengers);

            if ($schedule->available_seats < $participantCount) {
                throw new \Exception('ที่นั่งไม่เพียงพอ');
            }

            // Verify seat locks if seat-based booking
            if (!empty($seatIds)) {
                foreach ($seatIds as $seatId) {
                    if (!$this->seatLockService->isLockedByUser($scheduleId, $seatId, $userId)) {
                        throw new \Exception("ที่นั่ง {$seatId} ไม่ได้ถูกล็อคโดยคุณ");
                    }
                }
            }

            // Use pickup point price if region is specified
            $pricePerPerson = $schedule->effective_price;
            if ($pickupRegion) {
                $pickupPoint = SchedulePickupPoint::where('schedule_id', $scheduleId)
                    ->where('region', $pickupRegion)
                    ->first();
                if ($pickupPoint) {
                    $pricePerPerson = $pickupPoint->price;
                }
            }

            $totalAmount = $pricePerPerson * $participantCount;

            $booking = Booking::create([
                'booking_ref' => Booking::generateRef(),
                'user_id' => $userId,
                'schedule_id' => $scheduleId,
                'pickup_region' => $pickupRegion,
                'is_group' => $isGroup || $participantCount > 1,
                'group_name' => $groupName,
                'group_notes' => $groupNotes,
                'qr_code' => Booking::generateQrCode(),
                'status' => 'pending',
                'total_amount' => $totalAmount,
            ]);

            // Create passengers
            foreach ($passengers as $passengerData) {
                BookingPassenger::create([
                    'booking_id' => $booking->id,
                    ...$passengerData,
                ]);
            }

            // Create seats if seat-based
            if (!empty($seatIds)) {
                foreach ($seatIds as $index => $seatId) {
                    BookingSeat::create([
                        'booking_id' => $booking->id,
                        'schedule_id' => $scheduleId,
                        'seat_id' => $seatId,
                        'passenger_name' => $passengers[$index]['name'] ?? null,
                    ]);
                }
            }

            // Update booked seats count
            $schedule->increment('booked_seats', $participantCount);

            return $booking->load(['passengers', 'seats', 'schedule.trip']);
        });
    }

    public function confirmBooking(Booking $booking, string $paymentMethod, string $paymentRef): Booking
    {
        return DB::transaction(function () use ($booking, $paymentMethod, $paymentRef) {
            $booking->update([
                'status' => 'confirmed',
                'paid_amount' => $booking->total_amount,
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
        return DB::transaction(function () use ($booking, $reason) {
            $passengerCount = $booking->passengers()->count();

            $booking->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            // Release seats
            $schedule = $booking->schedule()->lockForUpdate()->first();
            $schedule->decrement('booked_seats', $passengerCount);

            // Release seat locks & delete booking seats
            foreach ($booking->seats as $seat) {
                $this->seatLockService->forceUnlock($booking->schedule_id, $seat->seat_id);
            }
            $booking->seats()->delete();

            return $booking->fresh(['passengers', 'schedule.trip']);
        });
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
