<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\TripSchedule;
use App\Services\ScheduleRallyService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "ช่วยกันเปิดรอบ" — เฉพาะคนที่จองรอบนี้แล้วเท่านั้นที่เห็น เพราะเป็นการชวน
 * ให้เขาช่วยหาเพื่อนมาเติมให้รอบของตัวเองได้ออกเดินทาง
 */
class ScheduleRallyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ScheduleRallyService $rally,
    ) {}

    public function show(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->hasBooking($user->id, $schedule->id)) {
            return $this->error('เฉพาะผู้ที่จองรอบนี้แล้วเท่านั้น', 403);
        }

        return $this->success($this->rally->forSchedule($schedule, $user));
    }

    /** ผู้ใช้มีการจองที่ยัง active อยู่ในรอบนี้ไหม (เจ้าของหรือเพื่อนร่วมทาง) */
    private function hasBooking(int $userId, int $scheduleId): bool
    {
        $memberBookingIds = BookingMember::where('user_id', $userId)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->pluck('booking_id');

        return Booking::where('schedule_id', $scheduleId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($userId, $memberBookingIds) {
                $q->where('user_id', $userId)
                    ->orWhereIn('id', $memberBookingIds);
            })
            ->exists();
    }
}
