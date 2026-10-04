<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Services\RentalPickListService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * ใบเตรียมของสำหรับคนจัดของ (บทบาทเสริม `packer`) — เปิดในแอปได้โดยไม่ต้องเข้าหลังบ้าน
 *
 * ตัวเลขชุดเดียวกับหน้า "อุปกรณ์เช่าที่ต้องเตรียม" ของแอดมิน (RentalPickListService)
 * ต่างกันแค่ตัดราคา รายได้ และเบอร์โทรลูกค้าออก — คนจัดของต้องรู้แค่ว่าของใคร กี่ชิ้น
 */
class PackingController extends Controller
{
    use ApiResponse;

    public function __construct(private RentalPickListService $pickList) {}

    public function schedules(): JsonResponse
    {
        return $this->success([
            'schedules' => array_map(
                fn (array $schedule) => Arr::except($schedule, ['rentals_revenue']),
                $this->pickList->schedules(),
            ),
        ]);
    }

    public function show(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->success($this->pickList->forPacker($schedule));
    }
}
