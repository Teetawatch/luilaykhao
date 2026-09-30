<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Services\ForceMajeureService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ยกเลิกรอบเพราะเหตุสุดวิสัย (น้ำป่า พายุ อุทยานสั่งปิด) แล้วให้ลูกค้าเลือกรอบใหม่เอง
 * — ปุ่มในหน้ารอบเดินทางของแอดมิน ดู ForceMajeureService
 */
class AdminForceMajeureController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ForceMajeureService $forceMajeure,
    ) {}

    public function show(int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        return $this->success($this->forceMajeure->overview($schedule));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
        ]);

        $schedule = TripSchedule::findOrFail($id);

        try {
            $result = $this->forceMajeure->postponeSchedule($schedule, $data['reason']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->forceMajeure->overview($schedule->fresh()),
            "ยกเลิกรอบแล้ว แจ้งลูกค้า {$result['bookings']} รายการให้เลือกรอบใหม่",
        );
    }
}
