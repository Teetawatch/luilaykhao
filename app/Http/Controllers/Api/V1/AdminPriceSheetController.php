<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MonthlyPriceSheetService;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "ราคาทริปรายเดือน" — ทริปไหนออกเดือนนี้บ้าง รอบไหน ราคาเท่าไร
 * ทีมงานเปิดหน้านี้เดือนละครั้งเพื่อก๊อปข้อความไปทำรูปโปรโมท
 */
class AdminPriceSheetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private MonthlyPriceSheetService $sheet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ], [
            'month.date_format' => 'เดือนต้องอยู่ในรูปแบบ YYYY-MM',
        ]);

        // ไม่ระบุเดือน = เดือนนี้ตามเวลาไทย (เซิร์ฟเวอร์ตั้ง UTC — ดึกวันสิ้นเดือน
        // now() จะข้ามไปเดือนถัดไปแล้วทั้งที่ไทยยังไม่ถึง)
        $month = $request->filled('month')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('month').'-01', 'Asia/Bangkok')
            : CarbonImmutable::now('Asia/Bangkok');

        return $this->success($this->sheet->forMonth($month));
    }
}
