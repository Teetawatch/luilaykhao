<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ClaimSeatHandoverRequest;
use App\Models\Booking;
use App\Services\SeatHandoverNotFound;
use App\Services\SeatHandoverService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ส่งต่อที่นั่ง — ฝั่งคนส่ง (เจ้าของการจอง / เพื่อนร่วมใบที่ส่งที่นั่งของตัวเอง)
 * และฝั่งคนรับ (เปิดลิงก์ กรอกข้อมูลตัวเอง แล้วรับที่นั่ง)
 */
class SeatHandoverController extends Controller
{
    use ApiResponse;

    public function __construct(private SeatHandoverService $handovers) {}

    public function index(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->first();

        if (! $booking || ! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        try {
            return $this->success($this->handovers->overview($booking, $request->user()));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function store(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'passenger_id' => ['required', 'integer'],
            'transfers_ownership' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $booking = Booking::where('booking_ref', $ref)->first();

        if (! $booking || ! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        try {
            $handover = $this->handovers->create(
                $booking,
                (int) $validated['passenger_id'],
                $request->user(),
                (bool) ($validated['transfers_ownership'] ?? false),
                $validated['note'] ?? null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->handovers->present($handover->load('creator'), withLink: true),
            'สร้างลิงก์ส่งต่อที่นั่งแล้ว ส่งให้คนที่จะไปแทนได้เลย',
            201,
        );
    }

    public function destroy(Request $request, string $ref, int $handoverId): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->first();

        if (! $booking || ! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        try {
            $this->handovers->cancel($booking, $handoverId, $request->user());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'ยกเลิกลิงก์แล้ว ลิงก์เดิมใช้ไม่ได้อีก');
    }

    public function show(Request $request, string $token): JsonResponse
    {
        try {
            return $this->success($this->handovers->preview($token, $request->user()));
        } catch (SeatHandoverNotFound $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function claim(ClaimSeatHandoverRequest $request, string $token): JsonResponse
    {
        try {
            $handover = $this->handovers->claim(
                $token,
                $request->user(),
                $request->validated(),
                $request->validated('channel'),
                $request->ip(),
            );
        } catch (SeatHandoverNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking = $handover->booking;

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'schedule_id' => $booking->schedule_id,
            'trip_title' => $booking->schedule?->trip?->title,
            'transfers_ownership' => (bool) $handover->transfers_ownership,
        ], 'รับที่นั่งเรียบร้อย ดูรายละเอียดทริปได้ที่การจองของฉัน');
    }
}
