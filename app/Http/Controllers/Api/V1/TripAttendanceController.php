<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Services\TripAttendanceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "ไปครบไหม" ในแอป — คนจองตอบทั้งใบ เพื่อนในใบจองตอบเฉพาะตัวเอง
 */
class TripAttendanceController extends Controller
{
    use ApiResponse;

    public function __construct(private TripAttendanceService $attendance) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->booking($ref);

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        return $this->success($this->attendance->summary($booking, $request->user()));
    }

    /** คนจองยืนยันทั้งใบ: not_going_ids = คนที่ไม่ไป */
    public function confirm(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'not_going_ids' => ['present', 'array', 'max:100'],
            'not_going_ids.*' => ['integer'],
        ]);

        $booking = $this->booking($ref);

        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return $this->error('เฉพาะคนจองเท่านั้นที่ยืนยันแทนทั้งกลุ่มได้', 403);
        }

        try {
            $this->attendance->confirm($booking, $validated['not_going_ids']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $summary = $this->attendance->summary($booking->fresh('schedule'), $request->user());

        return $this->success(
            $summary,
            $summary['going_count'] === $summary['total']
                ? 'ยืนยันแล้ว ไปครบ '.$summary['total'].' คน'
                : 'ยืนยันแล้ว ไป '.$summary['going_count'].' จาก '.$summary['total'].' คน ทีมงานจะไม่รอคนที่แจ้งไม่ไป',
        );
    }

    /** เปลี่ยนสถานะของคนเดียว — คนจองทำได้ทุกคน เพื่อนทำได้เฉพาะตัวเอง */
    public function update(Request $request, string $ref, int $passengerId): JsonResponse
    {
        $validated = $request->validate([
            'not_going' => ['required', 'boolean'],
        ]);

        $booking = $this->booking($ref);
        $user = $request->user();

        $passenger = BookingPassenger::where('booking_id', $booking->id)->whereKey($passengerId)->first();

        if (! $passenger || ! $booking->isAccessibleByUser($user->id)) {
            return $this->error('ไม่พบผู้เดินทางคนนี้ในการจอง', 404);
        }

        $isOwner = (int) $booking->user_id === (int) $user->id;
        $isSelf = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->where('passenger_id', $passenger->id)
            ->exists();

        if (! $isOwner && ! $isSelf) {
            return $this->error('แก้ได้เฉพาะชื่อของตัวเอง', 403);
        }

        try {
            $this->attendance->setNotGoing($booking, $passenger, (bool) $validated['not_going']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->attendance->summary($booking->fresh('schedule'), $user),
            $validated['not_going']
                ? 'แจ้งทีมงานแล้วว่า '.$passenger->displayName().' ไม่ไป'
                : 'บันทึกแล้วว่า '.$passenger->displayName().' ไป',
        );
    }

    private function booking(string $ref): Booking
    {
        return Booking::with('schedule.trip')->where('booking_ref', $ref)->firstOrFail();
    }
}
