<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\SeatLocked;
use App\Events\SeatReleased;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seat\LockSeatRequest;
use App\Models\TripSchedule;
use App\Services\SeatLockService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeatController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SeatLockService $seatLockService,
    ) {}

    public function lock(LockSeatRequest $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $userId = $request->user()->id;
        $seatIds = $request->seat_ids;

        $result = $this->seatLockService->lockMultiple($scheduleId, $seatIds, $userId);

        if ($result['locked']) {
            foreach ($seatIds as $seatId) {
                broadcast(new SeatLocked(
                    $scheduleId,
                    $seatId,
                    $result['expires_at'],
                    $schedule->available_seats,
                ))->toOthers();
            }

            return $this->success($result, 'ล็อคที่นั่งสำเร็จ');
        }

        return $this->error($result['message'] ?? 'ไม่สามารถล็อคที่นั่งได้', 409);
    }

    public function unlock(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $userId = $request->user()->id;

        $seatIds = $request->input('seat_ids', []);
        if (empty($seatIds)) {
            return $this->error('กรุณาระบุที่นั่งที่ต้องการปลดล็อค', 422);
        }

        $unlocked = $this->seatLockService->unlockMultiple($scheduleId, $seatIds, $userId);

        foreach ($seatIds as $seatId) {
            broadcast(new SeatReleased(
                $scheduleId,
                $seatId,
                $schedule->available_seats,
            ))->toOthers();
        }

        return $this->success([
            'unlocked_count' => $unlocked,
        ], 'ปลดล็อคที่นั่งสำเร็จ');
    }
}
