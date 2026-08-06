<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\LiveActivity;
use App\Services\TripActivityService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ทะเบียนการ์ด "วันเดินทาง" ที่กำลังอยู่บนหน้าจอล็อกของลูกค้า
 *
 * แอปเป็นคนเปิด Activity (ระบบ iOS ไม่ให้เซิร์ฟเวอร์เปิดโดยไม่มี token) แล้วส่ง
 * token ที่ได้มาฝากไว้ที่นี่ จากนั้นทุกอย่างที่เหลือเกิดฝั่งเซิร์ฟเวอร์ — แอปจะถูก
 * ปิด ถูก swipe ทิ้ง หรือแบตหมดแล้วเสียบชาร์จใหม่ก็ตาม การ์ดยังอัปเดตต่อ
 */
class LiveActivityController extends Controller
{
    use ApiResponse;

    public function __construct(private TripActivityService $activities) {}

    /**
     * ฝาก push token ของ Activity ที่แอปเพิ่งเปิด แล้วรับ state ปัจจุบันกลับไป
     * วาดทันที (ไม่ต้องรอรอบซิงก์นาทีถัดไป)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_ref' => ['required', 'string', 'max:40'],
            'push_token' => ['required', 'string', 'max:200'],
            'activity_id' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
        ]);

        $booking = $this->resolveBooking($request, $validated['booking_ref']);
        if (! $booking) {
            return $this->error('ไม่พบการจองนี้ในบัญชีของคุณ', 404);
        }

        $activity = $this->activities->register(
            $booking,
            (int) $request->user()->id,
            $validated['push_token'],
            $validated['activity_id'] ?? null,
            $validated['platform'] ?? 'ios',
        );

        return $this->success([
            'id' => $activity->id,
            'state' => $this->activities->stateFor($booking),
            'attributes' => $this->activities->attributesFor($booking),
        ], 'เปิดการ์ดวันเดินทางแล้ว');
    }

    /**
     * state ปัจจุบันของใบจอง — Android ใช้เส้นนี้วาด ongoing notification ตอนเปิด
     * แอป และ iOS ใช้ตอนอยากรู้ว่าควรเปิด Activity หรือยัง
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($request, $ref);
        if (! $booking) {
            return $this->error('ไม่พบการจองนี้ในบัญชีของคุณ', 404);
        }

        return $this->success([
            'state' => $this->activities->stateFor($booking),
            'attributes' => $this->activities->attributesFor($booking),
        ]);
    }

    /**
     * ผู้ใช้ปัดการ์ดทิ้ง / แอปปิด Activity เอง — เลิกยิงไปหา token นี้
     *
     * ไม่ต้องส่ง end push กลับไป เพราะฝั่งเครื่องปิดไปแล้ว การยิงซ้ำมีแต่จะได้
     * BadDeviceToken กลับมา
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string', 'max:200'],
        ]);

        LiveActivity::live()
            ->where('user_id', $request->user()->id)
            ->where('push_token', $validated['push_token'])
            ->update(['ended_at' => now()]);

        return $this->success(null, 'ปิดการ์ดวันเดินทางแล้ว');
    }

    /**
     * ใบจองที่ผู้ใช้คนนี้ "เห็นในแอป" — เจ้าของใบจอง หรือเพื่อนร่วมใบจองที่ตอบรับ
     * คำเชิญแล้ว (เกณฑ์เดียวกับที่ใช้ตัดสินสิทธิ์ SOS และห้องแชท)
     */
    private function resolveBooking(Request $request, string $ref): ?Booking
    {
        $booking = Booking::with(['schedule.trip', 'schedule.vehicle', 'pickupPoint'])
            ->where('booking_ref', $ref)
            ->first();

        if (! $booking) {
            return null;
        }

        $userId = (int) $request->user()->id;

        if ((int) $booking->user_id === $userId) {
            return $booking;
        }

        $isMember = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $userId)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->exists();

        return $isMember ? $booking : null;
    }
}
