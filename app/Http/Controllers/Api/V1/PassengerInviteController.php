<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ออกลิงก์ให้เพื่อนร่วมทางกรอกข้อมูลของตัวเอง
 *
 * คนจองกดสร้างลิงก์ต่อผู้โดยสารหนึ่งคน แล้วส่งลิงก์นั้นให้เจ้าตัว เพื่อนเปิดแล้ว
 * กรอกเอง โดยคนจองไม่ต้องรู้เลขบัตรประชาชนหรือโรคประจำตัวของเพื่อนเลย
 */
class PassengerInviteController extends Controller
{
    use ApiResponse;

    /** ลิงก์อายุสั้นพอที่จะไม่ค้างอยู่ในแชทกลุ่มเป็นเดือน */
    private const TTL_DAYS = 14;

    public function store(Request $request, string $ref, int $passengerId): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        // เฉพาะคนที่เข้าถึงการจองนี้ได้เท่านั้นที่ออกลิงก์ได้ ลิงก์นี้เปิดสิทธิ์
        // แก้ไขข้อมูลผู้โดยสารให้ใครก็ตามที่ถือมันอยู่
        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        if ($booking->status === 'cancelled') {
            return $this->error('การจองนี้ถูกยกเลิกแล้ว', 422);
        }

        $passenger = BookingPassenger::where('booking_id', $booking->id)
            ->where('id', $passengerId)
            ->first();

        if (! $passenger) {
            return $this->error('ไม่พบผู้โดยสารคนนี้ในการจอง', 404);
        }

        // ออกโทเคนใหม่ทุกครั้ง — ลิงก์เก่าที่เคยส่งไปจึงใช้ไม่ได้อีก
        $passenger->forceFill([
            'self_fill_token' => Str::random(40),
            'self_fill_expires_at' => now()->addDays(self::TTL_DAYS),
            'self_filled_at' => null,
        ])->save();

        return $this->success([
            'passenger_id' => $passenger->id,
            'name' => $passenger->name,
            'url' => route('public.passenger-fill.show', $passenger->self_fill_token),
            'expires_at' => $passenger->self_fill_expires_at->toIso8601String(),
            'expires_in_days' => self::TTL_DAYS,
        ], 'สร้างลิงก์แล้ว ส่งให้เพื่อนกรอกข้อมูลได้เลย');
    }

    /** ยกเลิกลิงก์ที่ส่งไปแล้ว เช่นส่งผิดคน */
    public function destroy(Request $request, string $ref, int $passengerId): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        BookingPassenger::where('booking_id', $booking->id)
            ->where('id', $passengerId)
            ->update(['self_fill_token' => null, 'self_fill_expires_at' => null]);

        return $this->success(null, 'ยกเลิกลิงก์แล้ว');
    }
}
