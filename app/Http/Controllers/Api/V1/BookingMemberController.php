<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Services\BookingMemberService;
use App\Services\CheckInPassService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingMemberController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BookingMemberService $members,
    ) {}

    /**
     * รายชื่อสมาชิกของการจอง — เจ้าของหรือสมาชิก active เท่านั้น
     */
    public function index(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($ref);
        $user = $request->user();

        if (! $booking->isAccessibleByUser($user->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูสมาชิกของการจองนี้', 403);
        }

        return $this->success(
            $this->members->roster($booking, $booking->user_id === $user->id),
        );
    }

    /**
     * เจ้าของสร้างคำเชิญเพื่อนหนึ่งคน — คืน token/ลิงก์สำหรับส่งต่อ
     */
    public function store(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'passenger_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:60'],
        ]);

        $booking = $this->resolveBooking($ref);

        if (! $this->isOwner($booking, $request)) {
            return $this->error('เฉพาะเจ้าของการจองเท่านั้นที่เชิญเพื่อนได้', 403);
        }

        try {
            $invite = $this->members->createInvite(
                $booking,
                $validated['passenger_id'] ?? null,
                $validated['label'] ?? null,
                $request->user()->id,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'id' => $invite->id,
            'invite_token' => $invite->invite_token,
            'invite_url' => url('/join/'.$invite->invite_token),
            'invite_label' => $invite->invite_label,
        ], 'สร้างคำเชิญสำเร็จ', 201);
    }

    /**
     * เจ้าของนำสมาชิกออก หรือยกเลิกคำเชิญ
     */
    public function destroy(Request $request, string $ref, int $memberId): JsonResponse
    {
        $booking = $this->resolveBooking($ref);

        if (! $this->isOwner($booking, $request)) {
            return $this->error('เฉพาะเจ้าของการจองเท่านั้นที่จัดการสมาชิกได้', 403);
        }

        try {
            $this->members->revoke($booking, $memberId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'นำสมาชิกออกแล้ว');
    }

    /**
     * เพื่อนเลือกว่าตัวเองคือใครในรายชื่อผู้เดินทาง — แล้วได้บัตรขึ้นรถของตัวเอง
     */
    public function claimPassenger(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'passenger_id' => ['required', 'integer'],
        ]);

        $booking = $this->resolveBooking($ref);

        try {
            $member = $this->members->claimPassenger($booking, $request->user(), (int) $validated['passenger_id']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'passenger_id' => $member->passenger_id,
            'check_in_passes' => app(CheckInPassService::class)->passesFor($booking->fresh('passengers'), $request->user()),
        ], 'บันทึกแล้ว บัตรขึ้นรถของคุณพร้อมใช้');
    }

    /**
     * พรีวิวคำเชิญก่อนกดรับ — ผู้ใช้ที่ล็อกอินด้วยวิธีใดก็ดูได้
     */
    public function showInvite(Request $request, string $token): JsonResponse
    {
        $invite = BookingMember::where('invite_token', $token)
            ->with(['booking.schedule.trip', 'invitedBy:id,name,nickname'])
            ->first();

        if (! $invite || ! $invite->isPending() || ! $invite->booking) {
            return $this->error('คำเชิญนี้ไม่ถูกต้องหรือถูกใช้ไปแล้ว', 404);
        }

        $booking = $invite->booking;
        $schedule = $booking->schedule;
        $trip = $schedule?->trip;

        return $this->success([
            'invite_label' => $invite->invite_label,
            'invited_by' => $invite->invitedBy?->nickname ?: $invite->invitedBy?->name,
            'booking_ref' => $booking->booking_ref,
            'trip_title' => $trip?->title,
            'departure_date' => $schedule?->departure_date?->toDateString(),
            'departs_at' => $schedule?->departs_at?->format('Y-m-d H:i:s'),
            'already_member' => $booking->isAccessibleByUser($request->user()->id),
        ]);
    }

    /**
     * รับคำเชิญ — ผูก user ที่ล็อกอินอยู่เข้ากับการจอง
     */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        try {
            $member = $this->members->acceptInvite($request->user(), $token);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking = $member->booking()->with('schedule.trip')->first();

        return $this->success([
            'booking_ref' => $booking?->booking_ref,
            'schedule_id' => $booking?->schedule_id,
            'trip_title' => $booking?->schedule?->trip?->title,
        ], 'เข้าร่วมการจองสำเร็จ');
    }

    private function resolveBooking(string $ref): Booking
    {
        return Booking::where('booking_ref', $ref)->firstOrFail();
    }

    private function isOwner(Booking $booking, Request $request): bool
    {
        return $booking->user_id === $request->user()->id;
    }
}
