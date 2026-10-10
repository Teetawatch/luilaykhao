<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingMember;
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

    /**
     * ลิงก์ของเพื่อนคนนี้ (/f/{token}) — ลิงก์เดียวที่ส่งให้เพื่อนได้เลย: บัตรขึ้นรถ,
     * กรอกข้อมูลของตัวเอง, เข้าห้องแชทในแอป, แจ้งว่าไปไม่ได้
     *
     * ออกครั้งแรกแล้วใช้ตัวเดิมตลอด (ส่งซ้ำในกลุ่มได้โดยลิงก์เก่าไม่ตาย) คนจองออก
     * ให้ได้ทุกคน เพื่อนที่ผูกกับชื่อตัวเองแล้วขอของตัวเองได้
     */
    public function passLink(Request $request, string $ref, int $passengerId): JsonResponse
    {
        $booking = Booking::with('schedule.trip')->where('booking_ref', $ref)->firstOrFail();
        $user = $request->user();

        $passenger = BookingPassenger::where('booking_id', $booking->id)->whereKey($passengerId)->first();

        if (! $passenger || ! $booking->isAccessibleByUser($user->id)) {
            return $this->error('ไม่พบผู้โดยสารคนนี้ในการจอง', 404);
        }

        $isOwner = (int) $booking->user_id === (int) $user->id;
        $isSelf = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->where('passenger_id', $passenger->id)
            ->exists();

        if (! $isOwner && ! $isSelf) {
            return $this->error('ขอลิงก์ได้เฉพาะของตัวเอง', 403);
        }

        if (! in_array($booking->status, ['confirmed', 'pending'], true)) {
            return $this->error('การจองนี้ไม่สามารถส่งลิงก์ให้เพื่อนได้แล้ว', 422);
        }

        $passenger->ensurePassToken();
        $url = $passenger->passUrl();
        $trip = $booking->schedule?->trip?->title;

        return $this->success([
            'passenger_id' => $passenger->id,
            'name' => $passenger->displayName(),
            'url' => $url,
            'share_text' => trim('ลิงก์ของ'.$passenger->displayName()
                .($trip ? " ทริป {$trip}" : '')
                ." — ดูบัตรขึ้นรถ กรอกข้อมูลของตัวเอง และเข้าห้องแชทของทริปได้ที่นี่\n{$url}"),
        ], 'สร้างลิงก์แล้ว');
    }

    /** เปลี่ยนลิงก์ของเพื่อน — ลิงก์เดิมใช้ไม่ได้อีก (เช่น หลุดไปในกลุ่มใหญ่) */
    public function revokePassLink(Request $request, string $ref, int $passengerId): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return $this->error('เฉพาะคนจองเท่านั้นที่เปลี่ยนลิงก์ได้', 403);
        }

        BookingPassenger::where('booking_id', $booking->id)
            ->whereKey($passengerId)
            ->update(['pass_token' => null]);

        return $this->success(null, 'ยกเลิกลิงก์เดิมแล้ว');
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
