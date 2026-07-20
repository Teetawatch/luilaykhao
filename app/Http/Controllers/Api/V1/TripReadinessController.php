<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\TripReadinessService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "ทริปนี้ไหวไหม" — เทียบความหนักของทริปกับประวัติการเดินของผู้ใช้
 */
class TripReadinessController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripReadinessService $readiness,
    ) {}

    /**
     * อ่านได้โดยไม่ต้องล็อกอิน — จะตอบว่าให้เข้าสู่ระบบก่อนแทนที่จะ 401
     * เส้นทางนี้เป็น public จึงต้องอ่าน user ผ่าน guard sanctum ตรง ๆ
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->firstOrFail();
        $user = auth('sanctum')->user();

        $evaluation = $this->readiness->evaluate($trip, $user);
        $evaluation['alternatives'] = $this->readiness->easierAlternatives($trip, $user);

        return $this->success($evaluation);
    }

    /**
     * บันทึกค่าอ้างอิงที่ผู้ใช้กรอกเอง สำหรับคนที่ยังไม่เคยเดินทางกับเรา
     */
    public function updateBaseline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'max_distance_km' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'max_elevation_gain_m' => ['nullable', 'integer', 'min:0', 'max:9000'],
        ], [], [
            'max_distance_km' => 'ระยะทางไกลสุดที่เคยเดิน',
            'max_elevation_gain_m' => 'ความสูงสะสมมากสุดที่เคยไต่',
        ]);

        if (($validated['max_distance_km'] ?? null) === null && ($validated['max_elevation_gain_m'] ?? null) === null) {
            return $this->error('กรุณากรอกอย่างน้อยหนึ่งอย่าง', 422);
        }

        $user = $request->user();
        $user->forceFill([
            'self_reported_max_distance_km' => $validated['max_distance_km'] ?? $user->self_reported_max_distance_km,
            'self_reported_max_elevation_m' => $validated['max_elevation_gain_m'] ?? $user->self_reported_max_elevation_m,
            'hiking_baseline_updated_at' => now(),
        ])->save();

        return $this->success(
            $this->readiness->baselineFor($user->fresh()),
            'บันทึกข้อมูลแล้ว',
        );
    }
}
