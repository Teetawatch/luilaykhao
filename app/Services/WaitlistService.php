<?php

namespace App\Services;

use App\Jobs\ProcessWaitlistJob;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\WaitlistEntry;
use App\Support\LoyaltyTier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    public const OFFER_TTL_MINUTES = 15;

    public function join(int $userId, int $scheduleId, int $seatCount = 1): WaitlistEntry
    {
        return DB::transaction(function () use ($userId, $scheduleId, $seatCount) {
            $schedule = TripSchedule::with('trip')->lockForUpdate()->findOrFail($scheduleId);

            if ($schedule->status === 'cancelled') {
                throw new \Exception('รอบเดินทางนี้ถูกยกเลิกแล้ว');
            }

            if ($schedule->effectiveDepartsAt()->isPast()) {
                throw new \Exception('รอบเดินทางนี้ผ่านมาแล้ว');
            }

            $schedule->syncBookedSeats();

            // ที่นั่งที่ "ว่าง" แต่ถูกกันไว้ให้คนที่ได้รับสิทธิ์ไปแล้ว ยังจองไม่ได้จริง
            // จึงต้องยอมให้คนใหม่ต่อคิวได้ ไม่งั้นจะตันทั้งสองทาง (จองก็ไม่ได้ ต่อคิวก็ไม่ได้)
            $bookable = $schedule->available_seats - $this->heldSeats($scheduleId);

            if ($bookable >= $seatCount) {
                throw new \Exception('ยังมีที่นั่งว่างอยู่ กรุณาจองโดยตรงได้เลย');
            }

            $hasActiveBooking = Booking::where('user_id', $userId)
                ->where('schedule_id', $scheduleId)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();

            if ($hasActiveBooking) {
                throw new \Exception('คุณมีการจองที่ใช้งานอยู่ในรอบเดินทางนี้แล้ว');
            }

            $hasActiveEntry = WaitlistEntry::where('user_id', $userId)
                ->where('schedule_id', $scheduleId)
                ->whereIn('status', ['waiting', 'offered'])
                ->exists();

            if ($hasActiveEntry) {
                throw new \Exception('คุณอยู่ในคิวรอของรอบเดินทางนี้แล้ว');
            }

            $entry = WaitlistEntry::create([
                'user_id' => $userId,
                'schedule_id' => $scheduleId,
                'seat_count' => $seatCount,
                // ล็อกลำดับไว้ตอนเข้าคิว — คนที่ต่อคิวไปแล้วจะไม่ถูกแซงเพราะ
                // อีกคนเพิ่งขึ้นระดับทีหลัง
                'priority' => LoyaltyTier::rank(LoyaltyAccount::tierForUser($userId)),
                'status' => 'waiting',
            ]);

            return $entry->load('schedule.trip');
        });
    }

    public function leave(int $userId, int $scheduleId): bool
    {
        $entry = WaitlistEntry::where('user_id', $userId)
            ->where('schedule_id', $scheduleId)
            ->whereIn('status', ['waiting', 'offered'])
            ->first();

        if (! $entry) {
            return false;
        }

        $wasOffered = $entry->status === 'offered';

        $entry->update(['status' => 'cancelled']);

        // สละสิทธิ์ที่ได้รับมา = ที่นั่งที่กันไว้ถูกปล่อยทันที ต้องส่งต่อให้คนถัดไป
        // เดี๋ยวนั้น ไม่ใช่รอจนกว่าจะมีคนยกเลิกการจองครั้งถัดไป
        if ($wasOffered) {
            ProcessWaitlistJob::dispatch($scheduleId);
        }

        return true;
    }

    /**
     * ที่นั่งที่ถูกกันไว้ให้คนในคิวที่ได้รับสิทธิ์แล้วและยังไม่หมดเวลา
     * — คนอื่นจองที่นั่งเหล่านี้ไม่ได้จนกว่าสิทธิ์จะหมดอายุหรือถูกสละ
     */
    public function heldSeats(int $scheduleId, ?int $exceptUserId = null): int
    {
        return (int) WaitlistEntry::where('schedule_id', $scheduleId)
            ->where('status', 'offered')
            ->where('expires_at', '>', now())
            ->when($exceptUserId, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->sum('seat_count');
    }

    public function markBooked(int $userId, int $scheduleId): void
    {
        WaitlistEntry::where('user_id', $userId)
            ->where('schedule_id', $scheduleId)
            ->whereIn('status', ['waiting', 'offered'])
            ->update(['status' => 'booked']);
    }

    /**
     * ตรวจสอบที่นั่งว่างและแจ้งเตือนคนถัดไปในคิว
     * Returns: จำนวน users ที่ถูก notify
     */
    public function processSchedule(int $scheduleId): int
    {
        // แจกสิทธิ์ในทรานแซกชัน (ล็อกแถวรอบไว้กันแจกซ้ำ) แล้วค่อยยิงแจ้งเตือน
        // หลัง commit — FCM เป็น HTTP call ต่อคน ถ้าทำคาไว้ในล็อก คนอื่นจะจอง
        // รอบนี้ไม่ได้เลยตลอดเวลาที่ยิง push
        $offered = DB::transaction(function () use ($scheduleId) {
            $schedule = TripSchedule::lockForUpdate()->find($scheduleId);
            if (! $schedule) {
                return [];
            }

            $schedule->syncBookedSeats();

            $available = $schedule->available_seats;
            if ($available <= 0) {
                return [];
            }

            // หักที่นั่งที่ "offer" ไปแล้วแต่ยังไม่หมดเวลา (soft-hold)
            $remaining = max(0, $available - $this->heldSeats($scheduleId));
            if ($remaining <= 0) {
                return [];
            }

            $waitingEntries = WaitlistEntry::where('schedule_id', $scheduleId)
                ->where('status', 'waiting')
                ->orderByDesc('priority')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $offered = [];

            foreach ($waitingEntries as $entry) {
                if ($remaining <= 0) {
                    break;
                }

                if ($entry->seat_count > $remaining) {
                    continue;
                }

                $expiresAt = now()->addMinutes(self::OFFER_TTL_MINUTES);

                $entry->update([
                    'status' => 'offered',
                    'offered_at' => now(),
                    'expires_at' => $expiresAt,
                ]);

                $offered[] = ['user_id' => $entry->user_id, 'expires_at' => $expiresAt];
                $remaining -= $entry->seat_count;
            }

            return $offered;
        });

        foreach ($offered as $offer) {
            SmartNotification::send(
                $offer['user_id'],
                'waitlist_offered',
                'มีที่นั่งว่างแล้ว!',
                'มีที่นั่งว่างในทริปที่คุณรอคิวอยู่ กรุณาจองภายใน '.self::OFFER_TTL_MINUTES.' นาที',
                [
                    'schedule_id' => (string) $scheduleId,
                    'expires_at' => $offer['expires_at']->toISOString(),
                    'route' => 'waitlist',
                ],
            );
        }

        return count($offered);
    }

    /**
     * หมดเวลา offers ที่ค้างอยู่ และ re-process คิวของแต่ละรอบ
     */
    public function expireStaleOffers(): array
    {
        $expiredEntries = WaitlistEntry::where('status', 'offered')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredEntries as $entry) {
            $entry->update(['status' => 'expired']);

            SmartNotification::send(
                $entry->user_id,
                'waitlist_expired',
                'หมดเวลาจองแล้ว',
                'สิทธิ์การจองของคุณหมดอายุแล้ว ที่นั่งถูกเปิดให้คนถัดไปในคิว',
                [
                    'schedule_id' => (string) $entry->schedule_id,
                    'route' => 'waitlist',
                ],
            );
        }

        $scheduleIds = $expiredEntries->pluck('schedule_id')->unique()->values();

        foreach ($scheduleIds as $scheduleId) {
            ProcessWaitlistJob::dispatch($scheduleId);
        }

        return [
            'expired_count' => $expiredEntries->count(),
            'schedules_reprocessed' => $scheduleIds->count(),
        ];
    }

    public function myEntries(int $userId): Collection
    {
        return WaitlistEntry::where('user_id', $userId)
            ->whereIn('status', ['waiting', 'offered'])
            ->with(['schedule.trip'])
            ->orderBy('created_at')
            ->get();
    }

    public function entryForSchedule(int $userId, int $scheduleId): ?WaitlistEntry
    {
        return WaitlistEntry::where('user_id', $userId)
            ->where('schedule_id', $scheduleId)
            ->whereIn('status', ['waiting', 'offered'])
            ->first();
    }

    public function positionInQueue(WaitlistEntry $entry): int
    {
        if ($entry->status !== 'waiting') {
            return 0;
        }

        // นับคนที่อยู่หน้าเรา: ระดับสูงกว่า หรือระดับเท่ากันแต่ต่อคิวก่อน
        // ถ้าต่อคิววินาทีเดียวกันเป๊ะ ใช้ id ตัดสิน ไม่งั้นทั้งคู่จะได้ลำดับที่ 1
        return WaitlistEntry::where('schedule_id', $entry->schedule_id)
            ->where('status', 'waiting')
            ->where(function ($q) use ($entry) {
                $q->where('priority', '>', $entry->priority)
                    ->orWhere(function ($same) use ($entry) {
                        $same->where('priority', $entry->priority)
                            ->where(function ($earlier) use ($entry) {
                                $earlier->where('created_at', '<', $entry->created_at)
                                    ->orWhere(function ($tie) use ($entry) {
                                        $tie->where('created_at', $entry->created_at)
                                            ->where('id', '<', $entry->id);
                                    });
                            });
                    });
            })
            ->count() + 1;
    }

    public function scheduleWaitlistCount(int $scheduleId): int
    {
        return WaitlistEntry::where('schedule_id', $scheduleId)
            ->whereIn('status', ['waiting', 'offered'])
            ->count();
    }
}
