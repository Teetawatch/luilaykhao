<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PriceSheetService;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "ราคาทริป" — ช่วงนี้มีทริปไหนบ้าง รอบไหน ราคาเท่าไร
 * ทีมงานเปิดหน้านี้เพื่อก๊อปข้อความไปทำรูปโปรโมท ("ทริปว่างสัปดาห์นี้",
 * "ทริปเดือนหน้า") ช่วงเวลาจึงเป็นอะไรก็ได้ ไม่ใช่แค่เดือน
 */
class AdminPriceSheetController extends Controller
{
    use ApiResponse;

    /**
     * ยาวสุดที่ยอมให้ถามในครั้งเดียว — กันคนพิมพ์ปีผิดแล้วลากทั้งฐานข้อมูลมา
     */
    private const MAX_DAYS = 400;

    public function __construct(
        private PriceSheetService $sheet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
        ], [
            'month.date_format' => 'เดือนต้องอยู่ในรูปแบบ YYYY-MM',
            'from.date_format' => 'วันเริ่มต้นต้องอยู่ในรูปแบบ YYYY-MM-DD',
            'to.date_format' => 'วันสิ้นสุดต้องอยู่ในรูปแบบ YYYY-MM-DD',
            'from.required_with' => 'ระบุวันสิ้นสุดแล้วต้องระบุวันเริ่มต้นด้วย',
            'to.required_with' => 'ระบุวันเริ่มต้นแล้วต้องระบุวันสิ้นสุดด้วย',
        ]);

        if ($request->filled('from') && $request->filled('to')) {
            $from = $this->thaiDate($request->input('from'));
            $to = $this->thaiDate($request->input('to'));

            if ($from->diffInDays($to, absolute: true) + 1 > self::MAX_DAYS) {
                return $this->error('ช่วงเวลายาวเกินไป เลือกได้ครั้งละไม่เกิน '.self::MAX_DAYS.' วัน', 422);
            }

            return $this->success($this->sheet->forRange($from, $to));
        }

        // ไม่ระบุอะไรเลย = เดือนนี้ตามเวลาไทย (เซิร์ฟเวอร์ตั้ง UTC — ดึกวันสิ้นเดือน
        // now() จะข้ามไปเดือนถัดไปแล้วทั้งที่ไทยยังไม่ถึง)
        $month = $request->filled('month')
            ? $this->thaiDate($request->input('month').'-01')
            : CarbonImmutable::now('Asia/Bangkok');

        return $this->success($this->sheet->forMonth($month));
    }

    private function thaiDate(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $value, 'Asia/Bangkok')->startOfDay();
    }
}
