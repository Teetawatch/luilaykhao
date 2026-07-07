<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingMemberService
{
    /**
     * สร้างคำเชิญสำหรับเพื่อนหนึ่งคน (สถานะ pending รอเพื่อนกดรับ)
     * เจ้าของการจองเท่านั้นที่เรียกได้ — ตรวจสิทธิ์ก่อนเรียกจาก controller
     */
    public function createInvite(Booking $booking, ?int $passengerId, ?string $label, int $invitedBy): BookingMember
    {
        if (! $this->bookingIsActive($booking)) {
            throw new \Exception('การจองนี้ไม่สามารถเชิญเพื่อนได้');
        }

        if ($this->occupiedSlots($booking) >= $this->maxMembers($booking)) {
            throw new \Exception('เชิญสมาชิกครบตามจำนวนผู้เดินทางแล้ว');
        }

        $passengerId = $this->validatePassengerId($booking, $passengerId);

        return BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => null,
            'passenger_id' => $passengerId,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_PENDING,
            'invite_token' => $this->generateToken(),
            'invite_label' => $label !== null ? trim($label) : null,
            'invited_by' => $invitedBy,
        ]);
    }

    /**
     * เพื่อนกดรับคำเชิญ — ผูก user ที่ล็อกอินอยู่ (ไม่ว่าจะล็อกอินด้วยวิธีใด)
     * เข้ากับการจอง โดยอ้างจาก user id ของ session ปัจจุบัน ไม่ใช่เบอร์/อีเมล
     */
    public function acceptInvite(User $user, string $token): BookingMember
    {
        $member = DB::transaction(function () use ($user, $token) {
            $invite = BookingMember::where('invite_token', $token)
                ->lockForUpdate()
                ->first();

            if (! $invite || ! $invite->isPending()) {
                throw new \Exception('คำเชิญนี้ไม่ถูกต้องหรือถูกใช้ไปแล้ว');
            }

            $booking = $invite->booking()->first();

            if (! $booking || ! $this->bookingIsActive($booking)) {
                throw new \Exception('การจองนี้ไม่เปิดให้เข้าร่วมแล้ว');
            }

            if ($booking->user_id === $user->id) {
                throw new \Exception('คุณเป็นเจ้าของการจองนี้อยู่แล้ว');
            }

            $existing = BookingMember::where('booking_id', $booking->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing && $existing->status === BookingMember::STATUS_ACTIVE) {
                throw new \Exception('คุณเข้าร่วมการจองนี้อยู่แล้ว');
            }

            $invite->forceFill([
                'user_id' => $user->id,
                'status' => BookingMember::STATUS_ACTIVE,
                'invite_token' => null,
                'accepted_at' => now(),
            ])->save();

            // หากเคยถูก revoke ไว้ ให้ลบแถวเก่าทิ้งกัน unique ชน
            if ($existing && $existing->id !== $invite->id) {
                $existing->delete();
            }

            return $invite->fresh();
        });

        // ถ้าการจองนี้เปิดแบ่งจ่ายไว้ ผูกสมาชิกใหม่กับส่วนแบ่งว่างทันที
        // และแจ้งยอดที่ต้องจ่ายผ่าน push
        app(SplitPaymentService::class)->linkMemberToShare($member);

        return $member;
    }

    /**
     * เจ้าของถอนสมาชิก หรือยกเลิกคำเชิญที่ยังไม่ถูกรับ
     */
    public function revoke(Booking $booking, int $memberId): void
    {
        $member = BookingMember::where('booking_id', $booking->id)
            ->where('id', $memberId)
            ->first();

        if (! $member) {
            throw new \Exception('ไม่พบสมาชิกที่ต้องการนำออก');
        }

        if ($member->role === BookingMember::ROLE_OWNER) {
            throw new \Exception('ไม่สามารถนำเจ้าของการจองออกได้');
        }

        $member->delete();
    }

    /**
     * รายชื่อสมาชิกของการจอง: เจ้าของ + สมาชิก/คำเชิญทั้งหมด
     *
     * @return array{owner: array, members: list<array>}
     */
    public function roster(Booking $booking): array
    {
        $owner = $booking->relationLoaded('user') ? $booking->user : $booking->user()->first();

        $members = $booking->members()
            ->whereIn('status', [BookingMember::STATUS_PENDING, BookingMember::STATUS_ACTIVE])
            ->with(['user:id,name,nickname,avatar', 'passenger:id,name,nickname'])
            ->orderBy('id')
            ->get()
            ->map(fn (BookingMember $m) => $this->presentMember($m))
            ->values()
            ->all();

        return [
            'owner' => $owner ? [
                'user_id' => $owner->id,
                'name' => $owner->name,
                'nickname' => $owner->nickname,
                'avatar_url' => $owner->avatar_url,
            ] : null,
            'members' => $members,
            'can_invite_more' => $this->occupiedSlots($booking) < $this->maxMembers($booking),
        ];
    }

    public function presentMember(BookingMember $m): array
    {
        return [
            'id' => $m->id,
            'status' => $m->status,
            'role' => $m->role,
            'invite_label' => $m->invite_label,
            'passenger_name' => $m->passenger?->nickname ?: $m->passenger?->name,
            'accepted_at' => $m->accepted_at?->toISOString(),
            'user' => $m->user ? [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'nickname' => $m->user->nickname,
                'avatar_url' => $m->user->avatar_url,
            ] : null,
        ];
    }

    /**
     * Best-effort: ผูก passenger เดิมที่เบอร์/อีเมลตรงกับบัญชีที่ลงทะเบียนแล้ว
     * เข้าเป็นสมาชิก active โดยอัตโนมัติ (ใช้ตอน backfill) — คืนจำนวนที่ผูกได้
     */
    public function autoLinkByContact(Booking $booking, bool $dryRun = false): int
    {
        $linked = 0;
        $reservedSlots = 0;
        $matchedUserIds = [];

        $passengers = $booking->relationLoaded('passengers')
            ? $booking->passengers
            : $booking->passengers()->get();

        foreach ($passengers as $passenger) {
            $user = $this->matchUserByContact($passenger->phone, $passenger->email);

            if (! $user || $user->id === $booking->user_id) {
                continue;
            }

            // กันผูกซ้ำ user เดียวกันจากหลาย passenger ในรอบเดียว
            if (in_array($user->id, $matchedUserIds, true)) {
                continue;
            }

            $exists = BookingMember::where('booking_id', $booking->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($exists) {
                continue;
            }

            if ($this->occupiedSlots($booking) + $reservedSlots >= $this->maxMembers($booking)) {
                break;
            }

            $matchedUserIds[] = $user->id;
            $linked++;

            if ($dryRun) {
                $reservedSlots++;

                continue;
            }

            BookingMember::create([
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'passenger_id' => $passenger->id,
                'role' => BookingMember::ROLE_COMPANION,
                'status' => BookingMember::STATUS_ACTIVE,
                'invite_label' => $passenger->nickname ?: $passenger->name,
                'invited_by' => $booking->user_id,
                'accepted_at' => now(),
            ]);
        }

        return $linked;
    }

    private function matchUserByContact(?string $phone, ?string $email): ?User
    {
        $phone = $phone !== null ? trim($phone) : null;
        $email = $email !== null ? trim($email) : null;

        if ($phone !== null && $phone !== '') {
            $user = User::where('phone', $phone)->first();
            if ($user) {
                return $user;
            }
        }

        // social login บางราย (เช่น LINE) ไม่มีอีเมลจริง ระบบเก็บเป็น @social.local
        // จึงข้ามการ match อีเมลปลอมเหล่านั้น
        if ($email !== null && $email !== '' && ! Str::endsWith($email, '@social.local')) {
            return User::where('email', $email)->first();
        }

        return null;
    }

    /**
     * จำนวนสมาชิกแอปสูงสุดของการจอง = จำนวนผู้เดินทาง (รวมเจ้าของ)
     */
    private function maxMembers(Booking $booking): int
    {
        $passengerCount = $booking->relationLoaded('passengers')
            ? $booking->passengers->count()
            : $booking->passengers()->count();

        return max(1, $passengerCount);
    }

    /**
     * ช่องที่ถูกใช้ไปแล้ว = เจ้าของ (1) + สมาชิก/คำเชิญที่ยัง active หรือ pending
     */
    private function occupiedSlots(Booking $booking): int
    {
        $taken = $booking->members()
            ->whereIn('status', [BookingMember::STATUS_PENDING, BookingMember::STATUS_ACTIVE])
            ->count();

        return $taken + 1;
    }

    private function validatePassengerId(Booking $booking, ?int $passengerId): ?int
    {
        if ($passengerId === null) {
            return null;
        }

        $belongs = $booking->passengers()->whereKey($passengerId)->exists();

        return $belongs ? $passengerId : null;
    }

    private function bookingIsActive(Booking $booking): bool
    {
        return in_array($booking->status, TripSchedule::ACTIVE_BOOKING_STATUSES, true);
    }

    private function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(24));
        } while (BookingMember::where('invite_token', $token)->exists());

        return $token;
    }
}
