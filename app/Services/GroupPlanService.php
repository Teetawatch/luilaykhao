<?php

namespace App\Services;

use App\Events\GroupPlanUpdated;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\GroupPlan;
use App\Models\GroupPlanMember;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates "ชวนเพื่อนมาเป็นกลุ่ม" (Group Trip Invite, host-pays-all).
 *
 * The host opens a plan and shares an invite code; friends join, claim a seat
 * and fill their traveller details in real time. All claimed seats are locked
 * under the HOST's user id so that, at checkout, BookingService sees them as
 * the host's own locks and produces a single booking the host pays for.
 */
class GroupPlanService
{
    public const TTL_MINUTES = 60;

    public function __construct(
        private SeatLockService $seatLockService,
        private BookingService $bookingService,
    ) {}

    public function create(User $host, TripSchedule $schedule, int $seatCount, ?string $name): GroupPlan
    {
        if ($schedule->status !== 'open') {
            throw new \Exception('รอบเดินทางนี้ไม่เปิดรับจอง');
        }

        $schedule->syncBookedSeats();
        if ($schedule->available_seats < $seatCount) {
            throw new \Exception('ที่นั่งว่างไม่พอสำหรับกลุ่มขนาดนี้');
        }

        $plan = DB::transaction(function () use ($host, $schedule, $seatCount, $name) {
            $plan = GroupPlan::create([
                'schedule_id' => $schedule->id,
                'host_user_id' => $host->id,
                'invite_code' => GroupPlan::generateInviteCode(),
                'name' => $name,
                'status' => 'open',
                'seat_count' => max(1, $seatCount),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            GroupPlanMember::create([
                'group_plan_id' => $plan->id,
                'user_id' => $host->id,
                'is_host' => true,
                'status' => 'joined',
                'passenger_name' => $host->name,
                'passenger_phone' => $host->phone,
                'passenger_email' => $host->email,
            ]);

            return $plan;
        });

        return $plan->fresh(['members.user', 'schedule.trip']);
    }

    public function join(GroupPlan $plan, User $user): GroupPlanMember
    {
        $this->assertOpen($plan);

        $existing = $plan->members()->where('user_id', $user->id)->first();
        if ($existing) {
            if ($existing->status === 'left') {
                $existing->update(['status' => 'joined']);
            }

            return $existing;
        }

        $activeCount = $plan->members()->where('status', '!=', 'left')->count();
        if ($activeCount >= $plan->seat_count) {
            throw new \Exception('กลุ่มนี้เต็มแล้ว');
        }

        $member = GroupPlanMember::create([
            'group_plan_id' => $plan->id,
            'user_id' => $user->id,
            'is_host' => false,
            'status' => 'joined',
            'passenger_name' => $user->name,
            'passenger_phone' => $user->phone,
            'passenger_email' => $user->email,
        ]);

        $this->broadcast($plan);

        return $member;
    }

    public function claimSeat(GroupPlan $plan, User $user, string $seatId, array $passenger): GroupPlanMember
    {
        $this->assertOpen($plan);

        $member = $plan->members()->where('user_id', $user->id)->first();
        if (! $member || $member->status === 'left') {
            throw new \Exception('คุณยังไม่ได้เข้าร่วมกลุ่มนี้');
        }

        if ($this->isSeatBooked($plan->schedule_id, $seatId)) {
            throw new \Exception("ที่นั่ง {$seatId} ถูกจองไปแล้ว");
        }

        // Prevent two members of the same group grabbing the same seat.
        $takenByGroupmate = $plan->members()
            ->where('user_id', '!=', $user->id)
            ->where('status', '!=', 'left')
            ->where('seat_id', $seatId)
            ->exists();
        if ($takenByGroupmate) {
            throw new \Exception("ที่นั่ง {$seatId} ถูกเพื่อนในกลุ่มเลือกไปแล้ว");
        }

        $ttl = $this->secondsUntilExpiry($plan);

        // Release this member's previous seat (if switching) before grabbing the new one.
        if ($member->seat_id && $member->seat_id !== $seatId) {
            $this->seatLockService->forceUnlock($plan->schedule_id, $member->seat_id);
        }

        $lock = $this->seatLockService->lock(
            $plan->schedule_id,
            $seatId,
            $plan->host_user_id,
            ['group_plan_id' => $plan->id],
            $ttl,
        );

        if (! ($lock['locked'] ?? false)) {
            throw new \Exception("ที่นั่ง {$seatId} ไม่ว่างแล้ว กรุณาเลือกที่อื่น");
        }

        $member->update([
            'seat_id' => $seatId,
            'passenger_title' => $passenger['title'] ?? $member->passenger_title,
            'passenger_name' => $passenger['name'] ?? $member->passenger_name,
            'passenger_phone' => $passenger['phone'] ?? $member->passenger_phone,
            'passenger_email' => $passenger['email'] ?? $member->passenger_email,
            'allergies' => $passenger['allergies'] ?? $member->allergies,
            'health_notes' => $passenger['health_notes'] ?? $member->health_notes,
            'status' => 'ready',
        ]);

        $this->broadcast($plan);

        return $member->fresh();
    }

    public function releaseSeat(GroupPlan $plan, User $user): void
    {
        $member = $plan->members()->where('user_id', $user->id)->first();
        if (! $member || ! $member->seat_id) {
            return;
        }

        $this->seatLockService->forceUnlock($plan->schedule_id, $member->seat_id);
        $member->update(['seat_id' => null, 'status' => 'joined']);

        $this->broadcast($plan);
    }

    public function leave(GroupPlan $plan, User $user): void
    {
        $member = $plan->members()->where('user_id', $user->id)->first();
        if (! $member) {
            return;
        }

        // The host leaving tears down the whole plan.
        if ($member->is_host) {
            $this->cancel($plan);

            return;
        }

        if ($member->seat_id) {
            $this->seatLockService->forceUnlock($plan->schedule_id, $member->seat_id);
        }
        $member->delete();

        $this->broadcast($plan);
    }

    public function checkout(GroupPlan $plan, User $host, ?int $pickupPointId = null, ?string $pickupRegion = null): Booking
    {
        if ($host->id !== $plan->host_user_id) {
            throw new \Exception('เฉพาะหัวหน้ากลุ่มเท่านั้นที่ชำระเงินได้');
        }
        $this->assertOpen($plan);

        $plan->loadMissing('members');
        $claimed = $plan->members
            ->whereNotNull('seat_id')
            ->where('status', '!=', 'left')
            ->values();

        if ($claimed->isEmpty()) {
            throw new \Exception('ยังไม่มีสมาชิกที่เลือกที่นั่ง');
        }

        $seatIds = $claimed->pluck('seat_id')->all();
        $passengers = $claimed->map(fn (GroupPlanMember $m) => $m->toPassengerData())->all();

        $booking = $this->bookingService->createBooking(
            userId: $host->id,
            scheduleId: $plan->schedule_id,
            passengers: $passengers,
            seatIds: $seatIds,
            pickupPointId: $pickupPointId,
            pickupRegion: $pickupRegion,
            isGroup: true,
            groupName: $plan->name,
            // ที่นั่งของกลุ่มถูกยึดไว้ใน group_plan_members แล้ว (durable) — ไม่ต้องพึ่ง Redis soft-lock
            // ที่อาจหมดอายุระหว่างรวมกลุ่ม การกันจองซ้ำยังอาศัย DB guard ใน BookingService ตามเดิม
            verifySeatLocks: false,
        );

        $plan->update(['status' => 'booked', 'booking_id' => $booking->id]);
        $this->broadcast($plan);

        return $booking;
    }

    public function cancel(GroupPlan $plan): void
    {
        $plan->loadMissing('members');
        foreach ($plan->members as $member) {
            if ($member->seat_id) {
                $this->seatLockService->forceUnlock($plan->schedule_id, $member->seat_id);
            }
        }

        $plan->update(['status' => 'cancelled']);
        $this->broadcast($plan);
    }

    /** Sweep stale open plans whose hold has lapsed; releases their seat locks. */
    public function expireStale(): int
    {
        $count = 0;
        GroupPlan::where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('members')
            ->chunkById(100, function ($plans) use (&$count) {
                foreach ($plans as $plan) {
                    foreach ($plan->members as $member) {
                        if ($member->seat_id) {
                            $this->seatLockService->forceUnlock($plan->schedule_id, $member->seat_id);
                        }
                    }
                    $plan->update(['status' => 'expired']);
                    $this->broadcast($plan);
                    $count++;
                }
            });

        return $count;
    }

    private function assertOpen(GroupPlan $plan): void
    {
        if (! $plan->isOpen()) {
            throw new \Exception('กลุ่มนี้ปิดรับแล้ว หรือหมดเวลาจองร่วมกัน');
        }
    }

    private function isSeatBooked(int $scheduleId, string $seatId): bool
    {
        return BookingSeat::where('schedule_id', $scheduleId)
            ->where('seat_id', $seatId)
            ->whereHas('booking', fn ($q) => $q
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
                ->where('is_join_trip', false))
            ->exists();
    }

    private function secondsUntilExpiry(GroupPlan $plan): int
    {
        if ($plan->expires_at === null) {
            return self::TTL_MINUTES * 60;
        }

        return max(60, (int) now()->diffInSeconds($plan->expires_at, false));
    }

    private function broadcast(GroupPlan $plan): void
    {
        broadcast(new GroupPlanUpdated($plan->fresh(['members.user', 'schedule.trip'])));
    }
}
