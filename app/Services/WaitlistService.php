<?php

namespace App\Services;

use App\Jobs\ProcessWaitlistJob;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\WaitlistEntry;
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

            if ($schedule->available_seats >= $seatCount) {
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

        $entry->update(['status' => 'cancelled']);

        return true;
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
        return DB::transaction(function () use ($scheduleId) {
            $schedule = TripSchedule::lockForUpdate()->find($scheduleId);
            if (! $schedule) {
                return 0;
            }

            $schedule->syncBookedSeats();

            $available = $schedule->available_seats;
            if ($available <= 0) {
                return 0;
            }

            // หักที่นั่งที่ "offer" ไปแล้วแต่ยังไม่หมดเวลา (soft-hold)
            $offeredSeats = WaitlistEntry::where('schedule_id', $scheduleId)
                ->where('status', 'offered')
                ->where('expires_at', '>', now())
                ->sum('seat_count');

            $remaining = max(0, $available - (int) $offeredSeats);
            if ($remaining <= 0) {
                return 0;
            }

            $waitingEntries = WaitlistEntry::where('schedule_id', $scheduleId)
                ->where('status', 'waiting')
                ->orderBy('created_at')
                ->get();

            $notified = 0;

            foreach ($waitingEntries as $entry) {
                if ($remaining <= 0) {
                    break;
                }

                if ($entry->seat_count > $remaining) {
                    continue;
                }

                $entry->update([
                    'status' => 'offered',
                    'offered_at' => now(),
                    'expires_at' => now()->addMinutes(self::OFFER_TTL_MINUTES),
                ]);

                SmartNotification::send(
                    $entry->user_id,
                    'waitlist_offered',
                    'มีที่นั่งว่างแล้ว!',
                    'มีที่นั่งว่างในทริปที่คุณรอคิวอยู่ กรุณาจองภายใน '.self::OFFER_TTL_MINUTES.' นาที',
                    [
                        'schedule_id' => (string) $scheduleId,
                        'expires_at' => now()->addMinutes(self::OFFER_TTL_MINUTES)->toISOString(),
                        'route' => 'waitlist',
                    ],
                );

                $remaining -= $entry->seat_count;
                $notified++;
            }

            return $notified;
        });
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

        return WaitlistEntry::where('schedule_id', $entry->schedule_id)
            ->where('status', 'waiting')
            ->where('created_at', '<', $entry->created_at)
            ->count() + 1;
    }

    public function scheduleWaitlistCount(int $scheduleId): int
    {
        return WaitlistEntry::where('schedule_id', $scheduleId)
            ->whereIn('status', ['waiting', 'offered'])
            ->count();
    }
}
