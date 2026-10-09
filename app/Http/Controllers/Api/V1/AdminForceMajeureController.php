<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ForceMajeureSeatHold;
use App\Models\TripSchedule;
use App\Services\ForceMajeureService;
use App\Services\PostponedBookingsBoard;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ยกเลิกรอบเพราะเหตุสุดวิสัย (น้ำป่า พายุ อุทยานสั่งปิด) หรือเพราะผู้ร่วมทริปไม่ครบ
 * แล้วให้ลูกค้าเลือกรอบใหม่เอง (คนไม่ครบเลือกรับเงินคืนแทนได้) — ปุ่มในหน้ารอบเดินทาง
 * และหน้าเรดาร์รอบเสี่ยงของแอดมิน ดู ForceMajeureService
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

    /** ทุกใบที่ถูกเลื่อน + การกันที่นั่งในรอบใหม่ — หน้า /admin/postponed */
    public function bookings(PostponedBookingsBoard $board): JsonResponse
    {
        return $this->success($board->build());
    }

    public function releaseHold(int $holdId): JsonResponse
    {
        $hold = ForceMajeureSeatHold::findOrFail($holdId);
        $this->forceMajeure->releaseHold($hold);

        return $this->success(null, "ปล่อยที่นั่ง {$hold->seat_count} ที่ที่กันไว้แล้ว คนทั่วไปจองได้ทันที");
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in([ForceMajeureService::KIND_FORCE_MAJEURE, ForceMajeureService::KIND_UNDERFILLED])],
            // คนไม่ครบใช้เหตุผลตั้งต้นได้ เหตุสุดวิสัยต้องบอกเสมอว่าเกิดอะไร
            'reason' => ['required_unless:kind,'.ForceMajeureService::KIND_UNDERFILLED, 'nullable', 'string', 'max:120'],
        ]);

        $schedule = TripSchedule::findOrFail($id);
        $kind = $data['kind'] ?? ForceMajeureService::KIND_FORCE_MAJEURE;

        try {
            $result = $this->forceMajeure->postponeSchedule($schedule, (string) ($data['reason'] ?? ''), $kind);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->forceMajeure->overview($schedule->fresh()),
            $kind === ForceMajeureService::KIND_UNDERFILLED
                ? "ยกเลิกรอบแล้ว แจ้งลูกค้า {$result['bookings']} รายการให้เลือกรอบใหม่หรือรับเงินคืน"
                : "ยกเลิกรอบแล้ว แจ้งลูกค้า {$result['bookings']} รายการให้เลือกรอบใหม่",
        );
    }

    /**
     * ย้อนการเลื่อน (กดผิดรอบ) — ได้เฉพาะตอนที่ยังไม่มีลูกค้าคนไหนเลือกรอบใหม่
     */
    public function revert(int $id): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($id);

        try {
            $result = $this->forceMajeure->revertSchedule($schedule);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->forceMajeure->overview($schedule->fresh()),
            "ย้อนการเลื่อนแล้ว รอบกลับมาตามกำหนดเดิม แจ้งลูกค้า {$result['bookings']} รายการแล้ว",
        );
    }
}
