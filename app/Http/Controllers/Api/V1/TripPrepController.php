<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\TripPrepService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "เตรียมตัวก่อนเดินทาง" ของใบจองหนึ่งใบ — หน้าแรกกับหน้าทริปของฉันในแอปอ่านจากที่นี่
 */
class TripPrepController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripPrepService $prep,
    ) {}

    /** เจ้าของใบจองหรือเพื่อนร่วมใบเท่านั้น — แต่ละคนเห็นเฉพาะเรื่องที่ตัวเองต้องทำ */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();
        $user = $request->user();

        if (! $booking->isAccessibleByUser($user->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูข้อมูลการจองนี้', 403);
        }

        return $this->success($this->prep->forBooking($booking, $user));
    }
}
