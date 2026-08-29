<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\SeatLocked;
use App\Events\SeatReleased;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seat\LockSeatRequest;
use App\Models\ScheduleVehicleOption;
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

    /**
     * คันที่ที่นั่งในคำขอนี้อยู่ — null = รอบมีรถคันเดียว, false = ส่ง id ที่ไม่ใช่
     * ของรอบนี้มา (กติกาเดียวกับ ScheduleController::seats)
     */
    private function resolveOption(TripSchedule $schedule, mixed $requestedId): ScheduleVehicleOption|null|false
    {
        $options = $schedule->vehicleOptions->where('is_active', true);
        if ($options->isEmpty()) {
            return null;
        }

        if (blank($requestedId)) {
            return $options->firstWhere(
                fn (ScheduleVehicleOption $option) => (float) $option->price_adjustment === 0.0
            ) ?? $options->first();
        }

        return $options->firstWhere('id', (int) $requestedId) ?? false;
    }

    public function lock(LockSeatRequest $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('vehicleOptions')->findOrFail($scheduleId);
        $userId = $request->user()->id;
        $seatIds = $request->seat_ids;

        // ที่นั่งผูกกับคัน — A1 ของบัสกับ A1 ของตู้ล็อกกันคนละใบ
        $option = $this->resolveOption($schedule, $request->input('vehicle_option_id'));
        if ($option === false) {
            return $this->error('ตัวเลือกยานพาหนะที่เลือกไม่อยู่ในรอบเดินทางนี้', 422);
        }
        if ($option && ! $option->seat_selection) {
            return $this->error($option->label.'ไม่ต้องเลือกที่นั่ง ทีมงานจัดที่นั่งให้หน้างาน', 422);
        }
        $optionId = (int) ($option?->id ?? 0);

        // รอบที่บินไปไม่มีผังที่นั่งให้จอง — กันไว้ตรงนี้ด้วย เผื่อแอปรุ่นเก่าที่ยัง
        // วาดผังจากข้อมูลที่ค้างอยู่ในเครื่องยิงเข้ามา
        if (! $schedule->allowsSeatSelection()) {
            return $this->error('รอบนี้เดินทางโดยเครื่องบิน ที่นั่งจัดโดยสายการบิน ไม่ต้องเลือกที่นั่งเอง', 422);
        }

        // กันตั้งแต่ล็อกที่นั่ง ไม่งั้นคนที่ยังไม่ถึงคิวจะล็อกที่นั่งกันคนอื่นไว้ได้
        // ทั้งที่ตัวเองจองไม่ได้
        if (! $schedule->isBookableBy($userId)) {
            return $this->error('รอบนี้ยังไม่เปิดจองสำหรับคุณ', 422);
        }

        $ttlSeconds = SeatLockService::lockTtlSeconds(count($seatIds), $userId);
        $result = $this->seatLockService->lockMultiple($scheduleId, $seatIds, $userId, [
            'pickup_point_id' => $request->input('pickup_point_id'),
            'pickup_region' => $request->input('pickup_region'),
        ], $ttlSeconds, $optionId);

        if ($result['locked']) {
            foreach ($seatIds as $seatId) {
                broadcast(new SeatLocked(
                    $scheduleId,
                    $seatId,
                    $result['expires_at'],
                    $schedule->available_seats,
                    $option?->id,
                ))->toOthers();
            }

            return $this->success($result, 'ล็อคที่นั่งสำเร็จ');
        }

        return $this->error($result['message'] ?? 'ไม่สามารถล็อคที่นั่งได้', 409);
    }

    public function unlock(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('vehicleOptions')->findOrFail($scheduleId);
        $userId = $request->user()->id;

        $seatIds = $request->input('seat_ids', []);
        if (empty($seatIds)) {
            return $this->error('กรุณาระบุที่นั่งที่ต้องการปลดล็อค', 422);
        }

        $option = $this->resolveOption($schedule, $request->input('vehicle_option_id'));
        if ($option === false) {
            return $this->error('ตัวเลือกยานพาหนะที่เลือกไม่อยู่ในรอบเดินทางนี้', 422);
        }

        $unlocked = $this->seatLockService->unlockMultiple(
            $scheduleId,
            $seatIds,
            $userId,
            (int) ($option?->id ?? 0),
        );

        foreach ($seatIds as $seatId) {
            broadcast(new SeatReleased(
                $scheduleId,
                $seatId,
                $schedule->available_seats,
                $option?->id,
            ))->toOthers();
        }

        return $this->success([
            'unlocked_count' => $unlocked,
        ], 'ปลดล็อคที่นั่งสำเร็จ');
    }

    public function active(Request $request): JsonResponse
    {
        return $this->success(
            $this->seatLockService->activeLocksForUser($request->user()->id),
        );
    }

    public function cancelActive(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $seatIds = $request->input('seat_ids', []);
        $result = $this->seatLockService->unlockActiveForUser(
            $scheduleId,
            $request->user()->id,
            is_array($seatIds) ? $seatIds : [],
            $request->filled('vehicle_option_id') ? (int) $request->input('vehicle_option_id') : null,
        );

        foreach ($result['seat_ids'] as $seatId) {
            broadcast(new SeatReleased(
                $scheduleId,
                $seatId,
                $schedule->available_seats,
                $result['vehicle_option_id'] ?? null,
            ))->toOthers();
        }

        return $this->success($result, 'ยกเลิกที่นั่งที่กำลังจองแล้ว');
    }
}
