<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class SeatLockService
{
    private const LOCK_TTL = 600; // 10 minutes

    private function redisAvailable(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function lock(int $scheduleId, string $seatId, int $userId): array
    {
        if (!$this->redisAvailable()) {
            return [
                'locked' => true,
                'expires_at' => now()->addSeconds(self::LOCK_TTL)->toISOString(),
            ];
        }

        $key = $this->seatKey($scheduleId, $seatId);
        $locked = Redis::set($key, $userId, 'EX', self::LOCK_TTL, 'NX');

        if ($locked) {
            return [
                'locked' => true,
                'expires_at' => now()->addSeconds(self::LOCK_TTL)->toISOString(),
            ];
        }

        $lockedBy = Redis::get($key);
        if ((int) $lockedBy === $userId) {
            Redis::expire($key, self::LOCK_TTL);
            return [
                'locked' => true,
                'expires_at' => now()->addSeconds(self::LOCK_TTL)->toISOString(),
            ];
        }

        return [
            'locked' => false,
            'message' => 'ที่นั่งนี้ถูกล็อคอยู่',
        ];
    }

    public function lockMultiple(int $scheduleId, array $seatIds, int $userId): array
    {
        $lockedSeats = [];

        foreach ($seatIds as $seatId) {
            $result = $this->lock($scheduleId, $seatId, $userId);
            if (!$result['locked']) {
                foreach ($lockedSeats as $lockedSeatId) {
                    $this->unlock($scheduleId, $lockedSeatId, $userId);
                }
                return [
                    'locked' => false,
                    'message' => "ที่นั่ง {$seatId} ถูกล็อคอยู่แล้ว",
                    'failed_seat' => $seatId,
                ];
            }
            $lockedSeats[] = $seatId;
        }

        return [
            'locked' => true,
            'seats' => $lockedSeats,
            'expires_at' => now()->addSeconds(self::LOCK_TTL)->toISOString(),
        ];
    }

    public function unlock(int $scheduleId, string $seatId, int $userId): bool
    {
        if (!$this->redisAvailable()) {
            return true;
        }

        $key = $this->seatKey($scheduleId, $seatId);
        $lockedBy = Redis::get($key);

        if ((int) $lockedBy === $userId) {
            Redis::del($key);
            return true;
        }

        return false;
    }

    public function unlockMultiple(int $scheduleId, array $seatIds, int $userId): int
    {
        $count = 0;
        foreach ($seatIds as $seatId) {
            if ($this->unlock($scheduleId, $seatId, $userId)) {
                $count++;
            }
        }
        return $count;
    }

    public function forceUnlock(int $scheduleId, string $seatId): void
    {
        if (!$this->redisAvailable()) {
            return;
        }
        Redis::del($this->seatKey($scheduleId, $seatId));
    }

    public function getSeatStatus(int $scheduleId, array $allSeatIds): array
    {
        $statuses = [];
        $bookedSeats = \App\Models\BookingSeat::where('schedule_id', $scheduleId)
            ->pluck('seat_id')
            ->toArray();

        $redisUp = $this->redisAvailable();

        foreach ($allSeatIds as $seatId) {
            if (in_array($seatId, $bookedSeats)) {
                $statuses[$seatId] = 'booked';
            } elseif ($redisUp && Redis::exists($this->seatKey($scheduleId, $seatId))) {
                $statuses[$seatId] = 'locked';
            } else {
                $statuses[$seatId] = 'available';
            }
        }

        return $statuses;
    }

    public function isLockedByUser(int $scheduleId, string $seatId, int $userId): bool
    {
        if (!$this->redisAvailable()) {
            return true;
        }
        $key = $this->seatKey($scheduleId, $seatId);
        return (int) Redis::get($key) === $userId;
    }

    private function seatKey(int $scheduleId, string $seatId): string
    {
        return "seat_lock:{$scheduleId}:{$seatId}";
    }
}
