<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Services\GiftService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftController extends Controller
{
    use ApiResponse;

    public function __construct(private GiftService $giftService) {}

    /**
     * ของขวัญที่ฉันเป็นผู้ให้ — ไว้ดูโค้ด/สถานะการรับของแต่ละชิ้น
     */
    public function sent(Request $request): JsonResponse
    {
        return $this->success($this->giftService->sentGifts($request->user()->id));
    }

    /**
     * ดูรายละเอียดของขวัญจากโค้ด ก่อนตัดสินใจกดรับ
     */
    public function preview(Request $request, string $code): JsonResponse
    {
        try {
            return $this->success($this->giftService->preview($code, $request->user()));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /**
     * กดรับของขวัญ — โอนการจองมาเป็นของผู้รับ
     */
    public function claim(Request $request, string $code): JsonResponse
    {
        try {
            $booking = $this->giftService->claim($code, $request->user());

            return $this->success(new BookingResource($booking), 'รับของขวัญสำเร็จ');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
