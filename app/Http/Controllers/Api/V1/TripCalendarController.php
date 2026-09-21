<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TripCalendarService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ปฏิทินทริปสาธารณะ — "เดือนนี้/เดือนหน้ามีทริปอะไร" ในคำตอบเดียว
 *
 * ไม่ต้องล็อกอินโดยตั้งใจ: หน้านี้เกิดมาเพื่อถูกแปะในไลน์ให้คนที่ยังไม่เคยเป็น
 * ลูกค้าเปิดดู
 */
class TripCalendarController extends Controller
{
    use ApiResponse;

    public function index(Request $request, TripCalendarService $calendar): JsonResponse
    {
        return $this->success($calendar->forMonth($request->query('month')));
    }
}
