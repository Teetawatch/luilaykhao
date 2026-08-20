<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HomeWidgetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeWidgetController extends Controller
{
    use ApiResponse;

    public function __construct(private HomeWidgetService $homeWidget) {}

    /**
     * ข้อมูลของวิดเจ็ตหน้าโฮม — ทริปถัดไป + ยอดที่ต้องจ่ายงวดหน้า.
     *
     * แอปเรียกทุกครั้งที่กลับมาหน้าจอแล้วเขียนผลลัพธ์ลง App Group (iOS) /
     * SharedPreferences (Android) ให้วิดเจ็ตอ่านไปวาด ดู [HomeWidgetService]
     */
    public function show(Request $request): JsonResponse
    {
        return $this->success($this->homeWidget->snapshotFor($request->user()->id));
    }
}
