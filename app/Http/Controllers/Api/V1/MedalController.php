<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MedalService;
use App\Support\MedalDesign;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ตู้เหรียญพิชิตของผู้ใช้ที่ล็อกอิน — ดู App\Services\MedalService
 */
class MedalController extends Controller
{
    use ApiResponse;

    public function __construct(private MedalService $medals) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->medals->forUser($request->user()->id));
    }

    /**
     * ปิดฉากฉลองเหรียญใหม่ — ไม่ส่ง ids = ทุกเหรียญที่ยังไม่เคยเห็น
     */
    public function markSeen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $updated = $this->medals->markSeen(
            $request->user()->id,
            array_map('intval', $validated['ids'] ?? []),
        );

        return $this->success(['updated' => $updated]);
    }

    /** ตัวเลือกไอคอน/สีสำหรับฟอร์มแก้ทริปของแอดมิน */
    public function designOptions(): JsonResponse
    {
        return $this->success(MedalDesign::options());
    }
}
