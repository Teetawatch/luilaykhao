<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\CreateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BookingService $bookingService,
    ) {}

    public function store(CreateBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->createBooking(
                userId: $request->user()->id,
                scheduleId: $request->schedule_id,
                passengers: $request->passengers,
                seatIds: $request->seat_ids ?? [],
                pickupRegion: $request->pickup_region,
                isGroup: (bool) $request->is_group,
                groupName: $request->group_name,
                groupNotes: $request->group_notes,
            );

            return $this->success(new BookingResource($booking), 'สร้างการจองสำเร็จ', 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->with(['schedule.trip', 'schedule.pickupPoints', 'seats', 'passengers'])
            ->firstOrFail();

        return $this->success(new BookingResource($booking));
    }

    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->with(['schedule.trip'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return $this->paginated($bookings->through(fn($b) => new BookingResource($b)));
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
}
