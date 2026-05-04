<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripScheduleResource;
use App\Models\TripSchedule;
use App\Services\SeatLockService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function seats(Request $request, int $id): JsonResponse
    {
        $schedule = TripSchedule::with('vehicle')->findOrFail($id);
        $schedule->syncBookedSeats(); // Sync real-time data to ensure accuracy
        $layout = $schedule->vehicle?->seat_layout;
        
        // If no layout, generate a default grid layout
        if (!$layout || !isset($layout['seats'])) {
            $total = $schedule->total_seats ?: 10;
            $cols = ['A', 'B', 'C', 'D'];
            $numCols = 4;
            $numRows = ceil($total / $numCols);
            
            $generatedSeats = [];
            for ($i = 0; $i < $total; $i++) {
                $row = floor($i / $numCols) + 1;
                $colIdx = $i % $numCols;
                $seatId = $cols[$colIdx] . $row;
                $generatedSeats[] = [
                    'id' => $seatId,
                    'row' => $row,
                    'column' => $colIdx + 1,
                    'label' => $seatId,
                ];
            }
            
            $layout = [
                'rows' => $numRows,
                'columns' => array_slice($cols, 0, $numCols),
                'seats' => $generatedSeats,
                'front_label' => 'หน้ารถ',
                'rear_label' => 'หลังรถ',
                'show_driver' => true,
                'driver_icon' => 'directions_car',
            ];
        }

        $allSeatIds = collect($layout['seats'])->pluck('id')->toArray();
        $statuses = $this->seatLockService->getSeatStatus(
            $id,
            $allSeatIds,
            $request->user()?->id,
        );

        $seats = collect($layout['seats'])->map(function ($seat) use ($statuses) {
            $seatStatus = $statuses[$seat['id']] ?? [
                'status' => 'available',
                'passenger_name' => null,
                'locked_ttl_seconds' => null,
                'locked_until' => null,
                'locked_by_current_user' => false,
            ];
            return [
                ...$seat,
                ...$seatStatus,
            ];
        });

        return $this->success([
            'has_seat_map' => true, // Always true now since we have a fallback
            'rows' => $layout['rows'] ?? 0,
            'columns' => $layout['columns'] ?? [],
            'seats' => $seats,
            'total_seats' => $schedule->total_seats,
            'available_seats' => $schedule->available_seats,
            'lock_ttl_seconds' => SeatLockService::lockTtlSeconds(),
            'front_seat' => $layout['front_seat'] ?? null,
            'last_row_center' => $layout['last_row_center'] ?? [],
            'front_label' => $layout['front_label'] ?? 'หน้ารถ',
            'rear_label' => $layout['rear_label'] ?? 'ท้ายรถ (สำหรับเก็บสัมภาระ)',
            'driver_icon' => $layout['driver_icon'] ?? 'directions_car',
            'show_driver' => $layout['show_driver'] ?? true,
        ]);
    }
}
