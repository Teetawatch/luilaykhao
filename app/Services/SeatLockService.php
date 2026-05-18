<?php

namespace App\Services;

use App\Models\TripSchedule;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class SeatLockService
{
    private const LOCK_TTL = 600;          // 10 minutes base
    private const LOCK_TTL_PER_SEAT = 300; // +5 minutes per additional seat

    public static function lockTtlSeconds(int $seatCount = 1): int
    {
        return self::LOCK_TTL + (max(1, $seatCount) - 1) * self::LOCK_TTL_PER_SEAT;
    }

    private function redisAvailable(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function lock(int $scheduleId, string $seatId, int $userId, array $metadata = [], int $ttlSeconds = self::LOCK_TTL): array
    {
        if (!$this->redisAvailable()) {
            return [
                'locked' => true,
                'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
            ];
        }

        $key = $this->seatKey($scheduleId, $seatId);
        $value = $this->lockValue($userId, $metadata);
        $locked = Redis::set($key, $value, 'EX', $ttlSeconds, 'NX');

        if ($locked) {
            return [
                'locked' => true,
                'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
            ];
        }

        $lockedBy = $this->lockUserId(Redis::get($key));
        if ($lockedBy === $userId) {
            Redis::setex($key, $ttlSeconds, $value);
            return [
                'locked' => true,
                'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
            ];
        }

        return [
            'locked' => false,
            'message' => 'ที่นั่งนี้ถูกล็อคอยู่',
        ];
    }

    public function lockMultiple(int $scheduleId, array $seatIds, int $userId, array $metadata = [], int $ttlSeconds = self::LOCK_TTL): array
    {
        $lockedSeats = [];

        foreach ($seatIds as $seatId) {
            $result = $this->lock($scheduleId, $seatId, $userId, $metadata, $ttlSeconds);
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
            'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
        ];
    }

    public function unlock(int $scheduleId, string $seatId, int $userId): bool
    {
        if (!$this->redisAvailable()) {
            return true;
        }

        $key = $this->seatKey($scheduleId, $seatId);
        $lockedBy = $this->lockUserId(Redis::get($key));

        if ($lockedBy === $userId) {
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

    public function activeLocksForUser(int $userId): array
    {
        if (!$this->redisAvailable()) {
            return [];
        }

        $groups = [];
        foreach (Redis::keys('seat_lock:*') as $key) {
            if (!preg_match('/seat_lock:(\d+):(.+)$/', (string) $key, $matches)) {
                continue;
            }

            $scheduleId = (int) $matches[1];
            $seatId = $matches[2];
            $redisKey = $this->seatKey($scheduleId, $seatId);
            $payload = $this->lockPayload(Redis::get($redisKey));
            $lockedBy = $this->lockUserId($payload);
            if ($lockedBy !== $userId) {
                continue;
            }

            $ttl = (int) Redis::ttl($redisKey);
            if ($ttl <= 0) {
                continue;
            }

            $groups[$scheduleId] ??= [
                'schedule_id' => $scheduleId,
                'seat_ids' => [],
                'locked_ttl_seconds' => $ttl,
                'pickup_point_id' => $payload['pickup_point_id'] ?? null,
                'pickup_region' => $payload['pickup_region'] ?? null,
            ];
            $groups[$scheduleId]['seat_ids'][] = $seatId;
            $groups[$scheduleId]['locked_ttl_seconds'] = min(
                $groups[$scheduleId]['locked_ttl_seconds'],
                $ttl,
            );
        }

        if (empty($groups)) {
            return [];
        }

        $schedules = TripSchedule::with('trip')
            ->whereIn('id', array_keys($groups))
            ->get()
            ->keyBy('id');

        return collect($groups)
            ->map(function (array $lock) use ($schedules) {
                $schedule = $schedules->get($lock['schedule_id']);
                if (!$schedule) {
                    return null;
                }

                sort($lock['seat_ids'], SORT_NATURAL);
                $ttl = (int) $lock['locked_ttl_seconds'];

                return [
                    'schedule_id' => $schedule->id,
                    'trip_id' => $schedule->trip_id,
                    'trip_title' => $schedule->trip?->title,
                    'trip' => [
                        'id' => $schedule->trip?->id,
                        'title' => $schedule->trip?->title,
                        'slug' => $schedule->trip?->slug,
                        'location' => $schedule->trip?->location,
                        'thumbnail_image' => $schedule->trip?->thumbnail_image,
                        'cover_image' => $schedule->trip?->cover_image,
                    ],
                    'schedule' => [
                        'id' => $schedule->id,
                        'departure_date' => $schedule->departure_date?->toDateString(),
                        'return_date' => $schedule->return_date?->toDateString(),
                        'status' => $schedule->status,
                        'transport_type' => $schedule->transport_type,
                    ],
                    'seat_ids' => array_values($lock['seat_ids']),
                    'seat_count' => count($lock['seat_ids']),
                    'pickup_point_id' => $lock['pickup_point_id'],
                    'pickup_region' => $lock['pickup_region'],
                    'status' => 'locked',
                    'locked_ttl_seconds' => $ttl,
                    'locked_until' => now()->addSeconds($ttl)->toISOString(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function unlockActiveForUser(int $scheduleId, int $userId, array $seatIds = []): array
    {
        $activeLock = collect($this->activeLocksForUser($userId))
            ->firstWhere('schedule_id', $scheduleId);

        if (!$activeLock) {
            return [
                'unlocked_count' => 0,
                'seat_ids' => [],
            ];
        }

        $activeSeatIds = $activeLock['seat_ids'] ?? [];
        $targetSeatIds = empty($seatIds)
            ? $activeSeatIds
            : array_values(array_intersect($seatIds, $activeSeatIds));

        $unlockedSeatIds = [];
        foreach ($targetSeatIds as $seatId) {
            if ($this->unlock($scheduleId, $seatId, $userId)) {
                $unlockedSeatIds[] = $seatId;
            }
        }

        return [
            'unlocked_count' => count($unlockedSeatIds),
            'seat_ids' => $unlockedSeatIds,
        ];
    }

    public function forceUnlock(int $scheduleId, string $seatId): void
    {
        if (!$this->redisAvailable()) {
            return;
        }
        Redis::del($this->seatKey($scheduleId, $seatId));
    }

    public function getSeatStatus(int $scheduleId, array $allSeatIds, ?int $userId = null): array
    {
        $statuses = [];
        $bookedSeats = \App\Models\BookingSeat::where('schedule_id', $scheduleId)
            ->whereHas('booking', fn ($query) => $query
                ->whereIn('status', \App\Models\TripSchedule::ACTIVE_BOOKING_STATUSES)
                ->where('is_join_trip', false))
            ->get(['seat_id', 'passenger_name'])
            ->keyBy('seat_id');

        $redisUp = $this->redisAvailable();

        foreach ($allSeatIds as $seatId) {
            if ($bookedSeats->has($seatId)) {
                $statuses[$seatId] = [
                    'status' => 'booked',
                    'passenger_name' => $bookedSeats->get($seatId)->passenger_name,
                    'locked_ttl_seconds' => null,
                    'locked_until' => null,
                    'locked_by_current_user' => false,
                ];
            } elseif ($redisUp && Redis::exists($this->seatKey($scheduleId, $seatId))) {
                $key = $this->seatKey($scheduleId, $seatId);
                $ttl = (int) Redis::ttl($key);
                if ($ttl <= 0) {
                    $statuses[$seatId] = [
                        'status' => 'available',
                        'passenger_name' => null,
                        'locked_ttl_seconds' => null,
                        'locked_until' => null,
                        'locked_by_current_user' => false,
                    ];
                    continue;
                }

                $lockedBy = $this->lockUserId(Redis::get($key));
                $statuses[$seatId] = [
                    'status' => 'locked',
                    'passenger_name' => null,
                    'locked_ttl_seconds' => $ttl,
                    'locked_until' => now()->addSeconds($ttl)->toISOString(),
                    'locked_by_current_user' => $userId !== null && $lockedBy === $userId,
                ];
            } else {
                $statuses[$seatId] = [
                    'status' => 'available',
                    'passenger_name' => null,
                    'locked_ttl_seconds' => null,
                    'locked_until' => null,
                    'locked_by_current_user' => false,
                ];
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
        return $this->lockUserId(Redis::get($key)) === $userId;
    }

    private function seatKey(int $scheduleId, string $seatId): string
    {
        return "seat_lock:{$scheduleId}:{$seatId}";
    }

    private function lockValue(int $userId, array $metadata = []): string
    {
        return json_encode([
            'user_id' => $userId,
            'pickup_point_id' => $metadata['pickup_point_id'] ?? null,
            'pickup_region' => $metadata['pickup_region'] ?? null,
        ]);
    }

    private function lockPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $raw = is_object($value) && method_exists($value, 'toString')
            ? $value->toString()
            : (string) $value;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['user_id' => is_numeric($raw) ? (int) $raw : null];
    }

    private function lockUserId(mixed $value): ?int
    {
        $payload = $this->lockPayload($value);
        $userId = $payload['user_id'] ?? null;
        return $userId === null ? null : (int) $userId;
    }
}
