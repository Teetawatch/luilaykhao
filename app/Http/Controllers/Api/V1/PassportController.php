<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ConquestMapService;
use App\Services\PassportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassportController extends Controller
{
    use ApiResponse;

    public function __construct(private PassportService $passportService) {}

    /**
     * สมุดสะสมการเดินทาง (Passport) — สถิติตลอดชีพ + ตราสะสมของผู้ใช้ที่ล็อกอิน.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $this->passportService->forUser($request->user()->id);

        $earned = collect($data['badges'])->where('earned', true)->count();

        return $this->success(array_merge($data, [
            'badges_earned_count' => $earned,
            'badges_total' => count($data['badges']),
        ]));
    }

    /**
     * แผนที่พิชิต — ทริปที่เดินจบแล้ววางบนแผนที่ + ความลึกรายภาค.
     */
    public function map(Request $request, ConquestMapService $conquest): JsonResponse
    {
        return $this->success($conquest->forUser($request->user()->id));
    }
}
