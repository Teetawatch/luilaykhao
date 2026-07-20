<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Services\TripProgressService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ความคืบหน้าระหว่างทริป — ทั้งฝั่งลูกค้า (ต้องล็อกอิน) และลิงก์ให้ที่บ้านดู
 * (สาธารณะผ่าน share_token)
 */
class TripProgressController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripProgressService $progress,
    ) {}

    /**
     * ความคืบหน้าของการจองนี้ — เจ้าของการจองหรือเพื่อนร่วมเดินทางเท่านั้น
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::with('schedule.trip')
            ->where('booking_ref', $ref)
            ->firstOrFail();

        if (! $this->canView($request->user()?->id, $booking)) {
            return $this->error('คุณไม่มีสิทธิ์ดูความคืบหน้าของการจองนี้', 403);
        }

        $schedule = $booking->schedule;

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางของการจองนี้', 404);
        }

        return $this->success([
            'trip_title' => $schedule->trip?->title,
            'departure_date' => $schedule->departure_date?->toDateString(),
            'progress' => $this->progress->forSchedule($schedule),
        ]);
    }

    /**
     * ลิงก์ให้ที่บ้านติดตาม — สาธารณะ ใช้ share_token ของการจอง
     *
     * จงใจส่งเฉพาะ "หมุดกำหนดการ" (ถึงจุดไหนแล้ว/เวลา) ไม่ส่งพิกัดสด เพราะรถ
     * คันเดียวกันมีลูกค้าคนอื่นนั่งอยู่ด้วย การเปิดพิกัดสดให้คนนอกดูจึงเป็นการ
     * เปิดเผยตำแหน่งของคนที่ไม่ได้ยินยอม
     */
    public function shared(string $token): JsonResponse
    {
        $booking = Booking::with('schedule.trip')
            ->where('share_token', strtolower(trim($token)))
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบลิงก์ติดตามนี้ อาจหมดอายุหรือถูกยกเลิก', 404);
        }

        $schedule = $booking->schedule;

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางของลิงก์นี้', 404);
        }

        if (in_array($booking->status, ['cancelled', 'refunded'], true)) {
            return $this->success([
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'cancelled' => true,
                'progress' => null,
            ]);
        }

        return $this->success([
            'trip_title' => $schedule->trip?->title,
            'departure_date' => $schedule->departure_date?->toDateString(),
            'cancelled' => false,
            'traveller_name' => $booking->user?->nickname ?: $booking->user?->name,
            'progress' => $this->progress->forSchedule($schedule),
        ]);
    }

    /** เจ้าของการจอง หรือเพื่อนร่วมเดินทางที่ยัง active */
    private function canView(?int $userId, Booking $booking): bool
    {
        if ($userId === null) {
            return false;
        }

        if ($booking->user_id === $userId) {
            return true;
        }

        return $booking->members()
            ->where('user_id', $userId)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->exists();
    }
}
