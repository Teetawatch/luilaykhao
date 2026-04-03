<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripScheduleResource;
use App\Models\TripSchedule;
use App\Services\SeatLockService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ScheduleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SeatLockService $seatLockService,
    ) {}

    public function show(int $id): JsonResponse
    {
        $schedule = TripSchedule::with(['trip', 'vehicle', 'pickupPoints'])->findOrFail($id);

        return $this->success(new TripScheduleResource($schedule));
    }

    public function seats(int $id): JsonResponse
    {
        $schedule = TripSchedule::with('vehicle')->findOrFail($id);

        if (!$schedule->vehicle || !$schedule->vehicle->seat_layout) {
            return $this->success([
                'has_seat_map' => false,
                'total_seats' => $schedule->total_seats,
                'available_seats' => $schedule->available_seats,
            ]);
        }

        $layout = $schedule->vehicle->seat_layout;
        $allSeatIds = collect($layout['seats'] ?? [])->pluck('id')->toArray();
        $statuses = $this->seatLockService->getSeatStatus($id, $allSeatIds);

        $seats = collect($layout['seats'] ?? [])->map(function ($seat) use ($statuses) {
            return [
                ...$seat,
                'status' => $statuses[$seat['id']] ?? 'available',
            ];
        });

        return $this->success([
            'has_seat_map' => true,
            'rows' => $layout['rows'] ?? 0,
            'columns' => $layout['columns'] ?? [],
            'seats' => $seats,
            'total_seats' => $schedule->total_seats,
            'available_seats' => $schedule->available_seats,
        ]);
    }
}
