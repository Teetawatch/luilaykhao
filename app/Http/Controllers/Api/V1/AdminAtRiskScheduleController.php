<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Services\AtRiskScheduleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "รอบเสี่ยงไม่ออก" — หน้ารวมรอบที่ใกล้เดินทางแต่คนยังไม่ครบขั้นต่ำ
 * พร้อมปุ่มลงมือแก้ในที่เดียว (ชวนช่วยกันเปิดรอบ / ลดราคารอบ / Flexi-Price / ย้ายคนรวมรอบ)
 */
class AdminAtRiskScheduleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AtRiskScheduleService $radar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $window = $request->integer('days') ?: AtRiskScheduleService::WINDOW_DAYS;
        $window = max(1, min(90, $window));

        $rows = $this->radar->atRisk($window);

        return $this->success([
            'schedules' => $rows->values(),
            'summary' => [
                'count' => $rows->count(),
                'with_bookings' => $rows->where('booked_seats', '>', 0)->count(),
                'critical' => $rows->where('severity', 'critical')->count(),
                'revenue_at_risk' => round((float) $rows->sum('revenue_at_risk'), 2),
                'min_seats' => $this->radar->minSeats(),
                'window_days' => $window,
            ],
        ]);
    }

    /** ยิงแจ้งเตือนหาผู้ที่จองรอบนี้แล้ว ให้ช่วยกันชวนเพื่อนมาเติมที่นั่ง */
    public function nudge(Request $request, int $id): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($id);

        try {
            $result = $this->radar->sendRallyNudge($schedule, $request->boolean('force'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            ['notified' => $result['notified']],
            "ส่งคำชวนถึงผู้ร่วมทริปแล้ว {$result['notified']} ท่าน",
        );
    }
}
