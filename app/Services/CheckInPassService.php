<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\User;

/**
 * บัตรขึ้นรถที่ผู้ใช้คนหนึ่งควรเห็นในใบจองหนึ่งใบ
 *
 * - เจ้าของใบจอง: บัตรของทุกคน + QR ของทั้งกลุ่ม (สแกนแล้วสตาฟเลือกได้ว่าใครมา)
 * - เพื่อนที่ผูกกับชื่อตัวเองแล้ว: บัตรของตัวเองใบเดียว — เพื่อนที่มาถึงก่อน
 *   เช็คอินแทนคนที่ยังไม่มาไม่ได้
 * - เพื่อนที่ยังไม่ได้เลือกว่าตัวเองคือใคร: QR ของทั้งกลุ่ม (แบบเดิม) และให้เลือกชื่อ
 *
 * ส่งไปพร้อมใบจองเพื่อให้แอปเก็บไว้เปิดตอนไม่มีสัญญาณได้ — หน้ารถตอนตีห้าคือที่
 * ที่สัญญาณหายบ่อยที่สุด
 */
class CheckInPassService
{
    /**
     * @return array<string, mixed>|null null = ผู้ใช้คนนี้ไม่ได้อยู่ในใบจอง
     */
    public function passesFor(Booking $booking, ?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $isOwner = (int) $booking->user_id === (int) $user->id;
        $member = null;

        if (! $isOwner) {
            $member = BookingMember::where('booking_id', $booking->id)
                ->where('user_id', $user->id)
                ->where('status', BookingMember::STATUS_ACTIVE)
                ->first();

            if (! $member) {
                return null;
            }
        }

        $passengers = ($booking->relationLoaded('passengers')
            ? $booking->passengers
            : $booking->passengers()->get())->sortBy('id')->values();

        $mine = $member?->passenger_id !== null
            ? $passengers->firstWhere('id', (int) $member->passenger_id)
            : null;

        $visible = match (true) {
            $isOwner => $passengers,
            $mine !== null => collect([$mine]),
            default => collect(),
        };

        $showGroup = ($isOwner || $mine === null) && filled($booking->qr_code);

        $claimed = $isOwner || $mine !== null
            ? []
            : BookingMember::where('booking_id', $booking->id)
                ->whereIn('status', [BookingMember::STATUS_ACTIVE, BookingMember::STATUS_PENDING])
                ->whereNotNull('passenger_id')
                ->pluck('passenger_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return [
            'viewer_role' => $isOwner ? 'owner' : 'member',
            'mine_passenger_id' => $mine?->id,
            'group' => $showGroup ? [
                'code' => $booking->qr_code,
                'passenger_count' => $passengers->count(),
            ] : null,
            'passes' => $visible
                ->filter(fn (BookingPassenger $p) => filled($p->qr_code))
                ->map(fn (BookingPassenger $p) => [
                    'passenger_id' => $p->id,
                    'name' => $p->displayName(),
                    'full_name' => trim(($p->title ? $p->title.' ' : '').$p->name),
                    'code' => $p->qr_code,
                    'checked_in' => $p->isCheckedIn(),
                    'checked_in_at' => $p->checked_in_at?->toIso8601String(),
                    'not_going' => $p->isNotGoing(),
                    'is_mine' => $mine !== null && (int) $p->id === (int) $mine->id,
                    // ลิงก์ของเพื่อนคนนี้ — เฉพาะเจ้าของ และเฉพาะที่เคยออกแล้ว
                    'pass_url' => $isOwner ? $p->passUrl() : null,
                ])
                ->values()
                ->all(),
            // เพื่อนที่ยังไม่ได้ผูกชื่อ เลือกได้จากคนที่ยังไม่มีใครรับ
            'pickable_passengers' => $isOwner || $mine !== null
                ? []
                : $passengers
                    ->reject(fn (BookingPassenger $p) => in_array((int) $p->id, $claimed, true))
                    ->map(fn (BookingPassenger $p) => [
                        'passenger_id' => $p->id,
                        'name' => $p->displayName(),
                        'full_name' => trim(($p->title ? $p->title.' ' : '').$p->name),
                    ])
                    ->values()
                    ->all(),
        ];
    }
}
