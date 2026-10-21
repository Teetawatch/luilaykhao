<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Services\OnsiteMoneyService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * เงินหน้างาน (หลังบ้าน การเงิน) — รอบที่มีเงินเก็บหน้างาน, เงินอยู่กับใคร, กดรับเงิน
 */
class AdminOnsiteMoneyController extends Controller
{
    use ApiResponse;

    public function __construct(private OnsiteMoneyService $money) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:365']]);
        $rounds = $this->money->recentRounds((int) ($validated['days'] ?? 60));

        return $this->success([
            'rounds' => $rounds,
            'pending_count' => collect($rounds)->where('is_settled', false)->count(),
            'pending_amount' => round((float) collect($rounds)->sum('pending_amount'), 2),
        ]);
    }

    public function show(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);

        return $this->success($this->money->summary($schedule));
    }

    public function receive(Request $request, int $scheduleId, int $userId): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);

        try {
            $this->money->receive($request->user(), $schedule, $userId, $validated['note'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->money->summary($schedule), 'บันทึกรับเงินแล้ว');
    }

    public function unreceive(int $scheduleId, int $userId): JsonResponse
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        $this->money->unreceive($schedule, $userId);

        return $this->success($this->money->summary($schedule), 'ยกเลิกการรับแล้ว');
    }
}
