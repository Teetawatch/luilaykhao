<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Services\OnsiteMoneyService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * สรุปยอดหน้างานของรอบ (ฝั่งทีมงาน) — ดู OnsiteMoneyService
 *
 * ดูได้: ทีมงานของรอบ (รวมที่ถูกปลดหลังจบทริปแล้ว) แอดมิน/โอเปอเรเตอร์ และคนที่ถือเงิน
 * ของรอบนี้อยู่ (เผื่อถูกถอดออกจากรอบไปแล้วแต่ยังต้องส่งเงิน)
 */
class OnsiteMoneyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private OnsiteMoneyService $money,
    ) {}

    public function show(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        $summary = $this->money->summary($schedule);

        if (! $this->allowed($request, $schedule, $summary)) {
            return $this->error('ดูได้เฉพาะทีมงานของรอบนี้', 403);
        }

        return $this->success($this->withMine($request, $summary));
    }

    /** ยืนยันส่งเงินที่ตัวเองถือให้บริษัท */
    public function submit(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        if (! $this->allowed($request, $schedule, $this->money->summary($schedule))) {
            return $this->error('ดูได้เฉพาะทีมงานของรอบนี้', 403);
        }

        try {
            $this->money->submit($request->user(), $schedule, $validated['note'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->withMine($request, $this->money->summary($schedule)), 'ยืนยันส่งเงินแล้ว รอการเงินตรวจรับ');
    }

    /** @param array<string, mixed> $summary */
    private function allowed(Request $request, TripSchedule $schedule, array $summary): bool
    {
        $user = $request->user();

        return $this->chatService->canModerate($user, $schedule)
            || collect($summary['holders'])->contains('user_id', $user->id);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function withMine(Request $request, array $summary): array
    {
        $summary['mine'] = collect($summary['holders'])->firstWhere('user_id', $request->user()->id);

        return $summary;
    }
}
