<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LongWeekendService;
use App\Support\TripListCache;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * หยุดยาวครั้งหน้า + รอบที่ไปกลับทัน — ไม่ต้องล็อกอิน ดู LongWeekendService
 *
 * แคชชุดเดียวกับรายการทริป (ล้างเมื่อรอบ/ทริป/วันหยุดเปลี่ยน)
 */
class LongWeekendController extends Controller
{
    use ApiResponse;

    public function index(Request $request, LongWeekendService $weekends): JsonResponse
    {
        return TripListCache::respond('long-weekends', $request, fn () => $this->success($weekends->upcoming()));
    }
}
