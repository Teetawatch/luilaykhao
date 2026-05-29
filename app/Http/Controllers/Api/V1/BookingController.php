<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\ChangePickupRequest;
use App\Http\Requests\Booking\CreateBookingRequest;
use App\Http\Requests\Booking\RescheduleBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\SchedulePhotoResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\WeatherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BookingService $bookingService,
        private WeatherService $weatherService,
    ) {}

    public function store(CreateBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->createBooking(
                userId: $request->user()->id,
                scheduleId: $request->schedule_id,
                passengers: $request->passengers,
                seatIds: $request->seat_ids ?? [],
                pickupPointId: $request->pickup_point_id,
                pickupRegion: $request->pickup_region,
                isGroup: (bool) $request->is_group,
                groupName: $request->group_name,
                groupNotes: $request->group_notes,
                promotionCode: $request->promotion_code,
                isJoinTrip: (bool) $request->is_join_trip,
                selectedAddons: $request->selected_addons ?? [],
            );

            return $this->success(new BookingResource($booking), 'สร้างการจองสำเร็จ', 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with([
                'schedule.trip',
                'schedule.pickupPoints',
                'schedule.staff',
                'pickupPoint',
                'seats',
                'passengers.pickupPoint',
                'installmentPayments',
                'staffReviews' => fn ($q) => $q->where('reviewer_user_id', auth()->id()),
            ])
            ->firstOrFail();

        $this->attachWeather($booking);

        return $this->success(new BookingResource($booking));
    }

    /**
     * Best-effort: attach the departure-day forecast to the booking's schedule
     * so the booking detail screen can surface it. Never let a weather lookup
     * failure break the booking response.
     */
    private function attachWeather(Booking $booking): void
    {
        $schedule = $booking->schedule;
        $trip = $schedule?->trip;

        if (! $schedule || ! $trip || $trip->latitude === null || $trip->longitude === null) {
            return;
        }

        $date = $schedule->departure_date?->toDateString();
        if (! $date) {
            return;
        }

        try {
            $forecast = $this->weatherService->forecastFor(
                (float) $trip->latitude,
                (float) $trip->longitude,
                $date,
            );

            if ($forecast) {
                $schedule->weather_forecast = $forecast->toPayload();
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to attach weather to booking', [
                'booking_ref' => $booking->booking_ref,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->with([
                'schedule.trip',
                'schedule.pickupPoints',
                'schedule.staff',
                'pickupPoint',
                'seats',
                'passengers.pickupPoint',
                'installmentPayments',
                'staffReviews' => fn ($q) => $q->where('reviewer_user_id', $request->user()->id),
            ])
            ->orderByDesc('created_at')
            ->get();

        return $this->success($bookings->map(fn ($b) => new BookingResource($b))->values());
    }

    public function cancel(CancelBookingRequest $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        try {
            $booking = $this->bookingService->cancelBooking($booking, $request->reason);

            return $this->success(new BookingResource($booking), 'ยกเลิกการจองสำเร็จ');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reschedule(RescheduleBookingRequest $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', Booking::MODIFIABLE_STATUSES)
            ->firstOrFail();

        try {
            $booking = $this->bookingService->rescheduleBooking(
                $booking,
                (int) $request->target_schedule_id,
                $request->seat_ids ?? [],
                $request->pickup_point_id,
            );

            return $this->success(new BookingResource($booking), 'เปลี่ยนวันเดินทางสำเร็จ');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function changePickup(ChangePickupRequest $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', Booking::MODIFIABLE_STATUSES)
            ->firstOrFail();

        try {
            $booking = $this->bookingService->changePickupPoint($booking, (int) $request->pickup_point_id);

            return $this->success(new BookingResource($booking), 'เปลี่ยนจุดรับสำเร็จ');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Photos taken by staff during the customer's trip, downloadable from
     * the customer app. Restricted to the booking owner; cancelled bookings
     * are excluded.
     */
    public function photos(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['cancelled'])
            ->with('schedule.photos')
            ->firstOrFail();

        $photos = $booking->schedule?->photos ?? collect();

        return $this->success(SchedulePhotoResource::collection($photos));
    }
}
