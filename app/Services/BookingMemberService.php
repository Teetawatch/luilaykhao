<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
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

        $passengerId = $this->validatePassengerId($booking, $passengerId);

        // คำเชิญที่ผูกกับชื่อคนใดคนหนึ่ง มีได้คนละหนึ่งใบตามจำนวนผู้เดินทางอยู่แล้ว
        // จึงไม่ต้องนับโควตารวม (คนจองที่ไม่ได้ไปเองจองให้เพื่อน 4 คน ต้องเชิญได้ครบ 4)
        if ($passengerId !== null) {
            if ($this->passengerIsClaimed($booking, $passengerId)) {
                throw new \Exception('ผู้เดินทางคนนี้มีคำเชิญหรือเข้าร่วมในแอปแล้ว');
            }
        } elseif ($this->occupiedSlots($booking) >= $this->maxMembers($booking)) {
            throw new \Exception('เชิญสมาชิกครบตามจำนวนผู้เดินทางแล้ว');
        }

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
     * คำเชิญที่รอรับของผู้เดินทางคนนี้ — มีอยู่แล้วใช้ใบเดิม ไม่มีก็ออกให้
     *
     * ใช้กับลิงก์ของเพื่อน (/f/{token}): เพื่อนเปิดลิงก์แล้วกด "เข้าแอป" ต้องได้
     * คำเชิญที่ผูกกับชื่อตัวเองเสมอ คืน null เมื่อที่นั่งนี้มีคนรับไปแล้ว
     */
    public function pendingInviteForPassenger(Booking $booking, BookingPassenger $passenger): ?BookingMember
    {
        if (! $this->bookingIsActive($booking) || (int) $passenger->booking_id !== (int) $booking->id) {
            return null;
        }

        $existing = BookingMember::where('booking_id', $booking->id)
            ->where('passenger_id', $passenger->id)
            ->whereIn('status', [BookingMember::STATUS_PENDING, BookingMember::STATUS_ACTIVE])
            ->first();

        if ($existing) {
            return $existing->isPending() ? $existing : null;
        }

        return BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => null,
            'passenger_id' => $passenger->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_PENDING,
            'invite_token' => $this->generateToken(),
            'invite_label' => $passenger->displayName(),
            'invited_by' => $booking->user_id,
        ]);
    }

    /**
     * เพื่อนที่เข้าร่วมแล้วแต่ยังไม่ได้บอกว่าตัวเองคือใครในรายชื่อ เลือกชื่อตัวเอง —
     * จากนั้นเห็นบัตรขึ้นรถของตัวเองใบเดียว
     */
    public function claimPassenger(Booking $booking, User $user, int $passengerId): BookingMember
    {
        return DB::transaction(function () use ($booking, $user, $passengerId) {
            $member = BookingMember::where('booking_id', $booking->id)
                ->where('user_id', $user->id)
                ->where('status', BookingMember::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $member) {
                throw new \Exception('คุณยังไม่ได้เป็นสมาชิกของการจองนี้');
            }

            if ($member->passenger_id !== null) {
                throw new \Exception('คุณเลือกชื่อของตัวเองไว้แล้ว ถ้าเลือกผิด ให้เจ้าของการจองนำคุณออกแล้วเชิญใหม่');
            }

            $passenger = BookingPassenger::where('booking_id', $booking->id)->whereKey($passengerId)->first();

            if (! $passenger) {
                throw new \Exception('ไม่พบผู้เดินทางคนนี้ในการจอง');
            }

            $claimed = BookingMember::where('booking_id', $booking->id)
                ->where('passenger_id', $passenger->id)
                ->whereIn('status', [BookingMember::STATUS_PENDING, BookingMember::STATUS_ACTIVE])
                ->lockForUpdate()
                ->first();

            if ($claimed && $claimed->isPending() && $claimed->user_id === null) {
                // คำเชิญของชื่อนี้ที่ยังไม่มีใครกดรับ — เจ้าตัวมาเลือกเองแล้ว ใบนั้นหมดหน้าที่
                $claimed->delete();
            } elseif ($claimed) {
                throw new \Exception('ชื่อนี้มีคนเลือกไปแล้ว');
            }

            $member->forceFill(['passenger_id' => $passenger->id])->save();

            return $member->fresh();
        });
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
     * $forOwner = ผู้เรียกเป็นเจ้าของการจอง จึงเห็นลิงก์ของคำเชิญที่ยังไม่ถูกใช้
     *
     * @return array{owner: array, members: list<array>}
     */
    public function roster(Booking $booking, bool $forOwner = false): array
    {
        $owner = $booking->relationLoaded('user') ? $booking->user : $booking->user()->first();

        $members = $booking->members()
            ->whereIn('status', [BookingMember::STATUS_PENDING, BookingMember::STATUS_ACTIVE])
            ->with(['user:id,name,nickname,avatar', 'passenger:id,name,nickname'])
            ->orderBy('id')
            ->get()
            ->map(fn (BookingMember $m) => $this->presentMember($m, $forOwner))
            ->values()
            ->all();

        $max = $this->maxMembers($booking);
        $occupied = $this->occupiedSlots($booking);

        return [
            'owner' => $owner ? [
                'user_id' => $owner->id,
                'name' => $owner->name,
                'nickname' => $owner->nickname,
                'avatar_url' => $owner->avatar_url,
            ] : null,
            'members' => $members,
            'can_invite_more' => $occupied < $max,
            // จำนวนที่นั่งทั้งหมด/ที่ยังเชิญได้ — หน้าจอเชิญเพื่อนบอกผู้ใช้ตรง ๆ ว่า
            // เหลืออีกกี่คน แทนที่จะให้ไปเดาเอาจากจำนวนผู้เดินทาง
            'max_members' => $max,
            'remaining_slots' => max(0, $max - $occupied),
            'viewer_is_owner' => $forOwner,
        ];
    }

    public function presentMember(BookingMember $m, bool $forOwner = false): array
    {
        $pendingToken = $forOwner && $m->isPending() ? $m->invite_token : null;

        return [
            'id' => $m->id,
            'status' => $m->status,
            'role' => $m->role,
            'invite_label' => $m->invite_label,
            'passenger_id' => $m->passenger_id,
            'passenger_name' => $m->passenger?->nickname ?: $m->passenger?->name,
            'accepted_at' => $m->accepted_at?->toISOString(),
            // คำเชิญที่ยังไม่ถูกรับ — เจ้าของส่งลิงก์เดิมซ้ำได้ ไม่ต้องสร้างใบใหม่
            // (ใบใหม่กินสิทธิ์อีกหนึ่งที่นั่ง แล้วเชิญคนที่เหลือไม่ได้)
            'invite_token' => $pendingToken,
            'invite_url' => $pendingToken !== null ? url('/join/'.$pendingToken) : null,
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

    private function passengerIsClaimed(Booking $booking, int $passengerId): bool
    {
        return BookingMember::where('booking_id', $booking->id)
            ->where('passenger_id', $passengerId)
            ->whereIn('status', [BookingMember::STATUS_PENDING, BookingMember::STATUS_ACTIVE])
            ->exists();
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
