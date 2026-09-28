<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripMedal;
use App\Services\ChallengeService;
use App\Services\MedalKudosService;
use App\Services\MedalService;
use App\Services\YearReviewService;
use App\Support\MedalDesign;
use App\Support\MedalGeometry;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * ทรงเหรียญที่เจ้าของเลือกตอนแชร์ — ลิงก์ /m/{token} ภาพ OG และโปรไฟล์
     * สาธารณะวาดตามนี้ shape = null กลับไปใช้แบบของทริป
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'shape' => ['present', 'nullable', 'string', Rule::in(MedalGeometry::SHAPES)],
        ]);

        try {
            $medal = $this->medals->setShape($request->user()->id, $id, $validated['shape']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->success([
            'id' => $medal->id,
            'shape' => $medal->shape,
            'share_url' => url('/m/'.$medal->share_token),
        ], 'บันทึกทรงเหรียญแล้ว');
    }

    /**
     * คนที่พิชิตรอบเดียวกับเหรียญ {id} ของผู้ใช้ — {id} ต้องเป็นเหรียญของตัวเอง
     * (คือหลักฐานว่าอยู่ในรอบนั้น) คนนอกรอบจึงดูรายชื่อไม่ได้
     */
    public function round(Request $request, int $id, MedalKudosService $kudos): JsonResponse
    {
        $mine = TripMedal::where('id', $id)->where('user_id', $request->user()->id)->first();

        if (! $mine) {
            return $this->error('ไม่พบเหรียญนี้', 404);
        }

        return $this->success($kudos->board($request->user(), $mine));
    }

    /** ปรบมือ/เลิกปรบมือให้เหรียญ {id} ของเพื่อนร่วมรอบ */
    public function kudos(Request $request, int $id, MedalKudosService $kudos): JsonResponse
    {
        $target = TripMedal::find($id);

        if (! $target) {
            return $this->error('ไม่พบเหรียญนี้', 404);
        }

        try {
            return $this->success($kudos->toggle($request->user(), $target));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 403);
        }
    }

    public function challenges(Request $request, ChallengeService $challenges): JsonResponse
    {
        return $this->success($challenges->forUser($request->user()->id));
    }

    public function yearReview(Request $request, YearReviewService $reviews): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        return $this->success($reviews->forUser($request->user(), $validated['year'] ?? null));
    }

    /** ตัวเลือกไอคอน/สีสำหรับฟอร์มแก้ทริปของแอดมิน */
    public function designOptions(): JsonResponse
    {
        return $this->success(MedalDesign::options());
    }
}
