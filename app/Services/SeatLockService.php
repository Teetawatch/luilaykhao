<?php

namespace App\Services;

use App\Models\BookingSeat;
use App\Models\LoyaltyAccount;
use App\Models\TripSchedule;
use App\Support\LoyaltyTier;
use Illuminate\Support\Facades\Redis;

class SeatLockService
{
    private const LOCK_TTL = 600;          // 10 minutes base

    private const LOCK_TTL_PER_SEAT = 300; // +5 minutes per additional seat

    /**
     * เวลาที่ล็อกที่นั่งได้ — ฐาน 10 นาที บวกเพิ่มตามจำนวนที่นั่ง และบวกโบนัส
     * ตามระดับสมาชิกถ้าระบุผู้จองมาด้วย (สมาชิกระดับสูงมีเวลากรอกข้อมูลนานขึ้น)
     */
    public static function lockTtlSeconds(int $seatCount = 1, ?int $userId = null): int
    {
        $ttl = self::LOCK_TTL + (max(1, $seatCount) - 1) * self::LOCK_TTL_PER_SEAT;

        $bonusMinutes = (int) LoyaltyTier::perk(
            LoyaltyAccount::tierForUser($userId),
            'seat_lock_bonus_minutes',
        );

        return $ttl + $bonusMinutes * 60;
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

    /**
     * ล็อกที่นั่งหนึ่งที่
     *
     * $vehicleOptionId คือคันที่ที่นั่งนี้อยู่ (0 = รอบนี้มีรถคันเดียว) — ที่นั่ง A1
     * ของรถบัสกับ A1 ของรถตู้เป็นคนละล็อกกัน
     */
    public function lock(int $scheduleId, string $seatId, int $userId, array $metadata = [], int $ttlSeconds = self::LOCK_TTL, int $vehicleOptionId = 0): array
    {
        if (! $this->redisAvailable()) {
            // ไม่มี Redis ก็ทำ soft-lock แบบกันชนระหว่างผู้ใช้ไม่ได้ (ไม่มี shared store)
            // แต่ยังกันไม่ให้แจกล็อกบนที่นั่งที่ "ถูกจองจริง" แล้วได้ โดยอ่านจาก booking_seats ตรง ๆ
            // ส่วนการกันจองซ้ำตอน commit อาศัย DB guard + lockForUpdate ใน BookingService
            if ($this->isSeatBooked($scheduleId, $seatId, $vehicleOptionId)) {
                return [
                    'locked' => false,
                    'message' => 'ที่นั่งนี้ถูกจองไปแล้ว',
                ];
            }

            return [
                'locked' => true,
                'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
            ];
        }

        $key = $this->seatKey($scheduleId, $seatId, $vehicleOptionId);
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

    public function lockMultiple(int $scheduleId, array $seatIds, int $userId, array $metadata = [], int $ttlSeconds = self::LOCK_TTL, int $vehicleOptionId = 0): array
    {
        $lockedSeats = [];

        foreach ($seatIds as $seatId) {
            $result = $this->lock($scheduleId, $seatId, $userId, $metadata, $ttlSeconds, $vehicleOptionId);
            if (! $result['locked']) {
                foreach ($lockedSeats as $lockedSeatId) {
                    $this->unlock($scheduleId, $lockedSeatId, $userId, $vehicleOptionId);
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

    public function unlock(int $scheduleId, string $seatId, int $userId, int $vehicleOptionId = 0): bool
    {
        if (! $this->redisAvailable()) {
            return true;
        }

        $key = $this->seatKey($scheduleId, $seatId, $vehicleOptionId);
        $lockedBy = $this->lockUserId(Redis::get($key));

        if ($lockedBy === $userId) {
            Redis::del($key);

            return true;
        }

        return false;
    }

    public function unlockMultiple(int $scheduleId, array $seatIds, int $userId, int $vehicleOptionId = 0): int
    {
        $count = 0;
        foreach ($seatIds as $seatId) {
            if ($this->unlock($scheduleId, $seatId, $userId, $vehicleOptionId)) {
                $count++;
            }
        }

        return $count;
    }

    public function activeLocksForUser(int $userId): array
    {
        if (! $this->redisAvailable()) {
            return [];
        }

        $groups = [];
        foreach (Redis::keys('seat_lock:*') as $key) {
            // คีย์รูปแบบเก่า (ไม่มีช่องคัน) ตกไปโดยไม่เข้าเงื่อนไข — ดู seatKey()
            if (! preg_match('/seat_lock:(\d+):(\d+):(.+)$/', (string) $key, $matches)) {
                continue;
            }

            $scheduleId = (int) $matches[1];
            $vehicleOptionId = (int) $matches[2];
            $seatId = $matches[3];
            $redisKey = $this->seatKey($scheduleId, $seatId, $vehicleOptionId);
            $payload = $this->lockPayload(Redis::get($redisKey));
            $lockedBy = $this->lockUserId($payload);
            if ($lockedBy !== $userId) {
                continue;
            }

            $ttl = (int) Redis::ttl($redisKey);
            if ($ttl <= 0) {
                continue;
            }

            // จัดกลุ่มตาม (รอบ, คัน) — ล็อกข้ามคันในรอบเดียวกันเป็นคนละใบจอง
            $groupKey = $scheduleId.':'.$vehicleOptionId;
            $groups[$groupKey] ??= [
                'schedule_id' => $scheduleId,
                'vehicle_option_id' => $vehicleOptionId,
                'seat_ids' => [],
                'locked_ttl_seconds' => $ttl,
                'pickup_point_id' => $payload['pickup_point_id'] ?? null,
                'pickup_region' => $payload['pickup_region'] ?? null,
            ];
            $groups[$groupKey]['seat_ids'][] = $seatId;
            $groups[$groupKey]['locked_ttl_seconds'] = min(
                $groups[$groupKey]['locked_ttl_seconds'],
                $ttl,
            );
        }

        if (empty($groups)) {
            return [];
        }

        $schedules = TripSchedule::with('trip')
            ->whereIn('id', collect($groups)->pluck('schedule_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return collect($groups)
            ->map(function (array $lock) use ($schedules) {
                $schedule = $schedules->get($lock['schedule_id']);
                if (! $schedule) {
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
                        'departs_at' => $schedule->departs_at?->format('Y-m-d H:i:s'),
                        'return_date' => $schedule->return_date?->toDateString(),
                        'status' => $schedule->status,
                        'transport_type' => $schedule->transport_type,
                    ],
                    'vehicle_option_id' => $lock['vehicle_option_id'] ?: null,
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

    public function unlockActiveForUser(int $scheduleId, int $userId, array $seatIds = [], ?int $vehicleOptionId = null): array
    {
        // ไม่ระบุคันมา = ปลดล็อกใบที่ค้างอยู่ในรอบนี้ใบไหนก็ได้ (พฤติกรรมเดิม)
        $activeLock = collect($this->activeLocksForUser($userId))
            ->where('schedule_id', $scheduleId)
            ->when(
                $vehicleOptionId !== null,
                fn ($locks) => $locks->where('vehicle_option_id', $vehicleOptionId ?: null),
            )
            ->first();

        if (! $activeLock) {
            return [
                'unlocked_count' => 0,
                'seat_ids' => [],
                'vehicle_option_id' => null,
            ];
        }

        $lockedOptionId = (int) ($activeLock['vehicle_option_id'] ?? 0);
        $activeSeatIds = $activeLock['seat_ids'] ?? [];
        $targetSeatIds = empty($seatIds)
            ? $activeSeatIds
            : array_values(array_intersect($seatIds, $activeSeatIds));

        $unlockedSeatIds = [];
        foreach ($targetSeatIds as $seatId) {
            if ($this->unlock($scheduleId, $seatId, $userId, $lockedOptionId)) {
                $unlockedSeatIds[] = $seatId;
            }
        }

        return [
            'unlocked_count' => count($unlockedSeatIds),
            'seat_ids' => $unlockedSeatIds,
            'vehicle_option_id' => $lockedOptionId ?: null,
        ];
    }

    public function forceUnlock(int $scheduleId, string $seatId, int $vehicleOptionId = 0): void
    {
        if (! $this->redisAvailable()) {
            return;
        }
        Redis::del($this->seatKey($scheduleId, $seatId, $vehicleOptionId));
    }

    public function getSeatStatus(int $scheduleId, array $allSeatIds, ?int $userId = null, int $vehicleOptionId = 0): array
    {
        $statuses = [];
        $bookedSeats = BookingSeat::where('schedule_id', $scheduleId)
            ->where('vehicle_option_id', $vehicleOptionId)
            ->whereHas('booking', fn ($query) => $query
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
                ->where('is_join_trip', false))
            ->with('booking:id,user_id,booking_ref')
            ->get(['id', 'booking_id', 'seat_id', 'passenger_name'])
            ->keyBy('seat_id');

        $redisUp = $this->redisAvailable();

        foreach ($allSeatIds as $seatId) {
            if ($bookedSeats->has($seatId)) {
                $seat = $bookedSeats->get($seatId);
                // ที่นั่งที่ "ตัวเองจองไว้แล้ว" ต้องบอกให้รู้ ไม่งั้นลูกค้าเห็นแค่ "จองแล้ว"
                // แล้วเข้าใจว่ามีคนอื่นแย่งไป ทั้งที่เป็นใบจองของตัวเอง
                $isOwnBooking = $userId !== null && (int) $seat->booking?->user_id === $userId;

                $statuses[$seatId] = [
                    'status' => 'booked',
                    'passenger_name' => $seat->passenger_name,
                    'locked_ttl_seconds' => null,
                    'locked_until' => null,
                    'locked_by_current_user' => false,
                    'booked_by_current_user' => $isOwnBooking,
                    'booking_ref' => $isOwnBooking ? $seat->booking?->booking_ref : null,
                ];
            } elseif ($redisUp && Redis::exists($this->seatKey($scheduleId, $seatId, $vehicleOptionId))) {
                $key = $this->seatKey($scheduleId, $seatId, $vehicleOptionId);
                $ttl = (int) Redis::ttl($key);
                if ($ttl <= 0) {
                    $statuses[$seatId] = [
                        'status' => 'available',
                        'passenger_name' => null,
                        'locked_ttl_seconds' => null,
                        'locked_until' => null,
                        'locked_by_current_user' => false,
                        'booked_by_current_user' => false,
                        'booking_ref' => null,
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
                    'booked_by_current_user' => false,
                    'booking_ref' => null,
                ];
            } else {
                $statuses[$seatId] = [
                    'status' => 'available',
                    'passenger_name' => null,
                    'locked_ttl_seconds' => null,
                    'locked_until' => null,
                    'locked_by_current_user' => false,
                    'booked_by_current_user' => false,
                    'booking_ref' => null,
                ];
            }
        }

        return $statuses;
    }

    public function isLockedByUser(int $scheduleId, string $seatId, int $userId, int $vehicleOptionId = 0): bool
    {
        if (! $this->redisAvailable()) {
            return true;
        }
        $key = $this->seatKey($scheduleId, $seatId, $vehicleOptionId);

        return $this->lockUserId(Redis::get($key)) === $userId;
    }

    /**
     * ที่นั่งถูกจองจริงหรือยัง (อ่านจาก source of truth = booking_seats ของการจองที่ยัง active)
     * ใช้เป็น fallback ตอน Redis ใช้ไม่ได้
     */
    private function isSeatBooked(int $scheduleId, string $seatId, int $vehicleOptionId = 0): bool
    {
        return BookingSeat::where('schedule_id', $scheduleId)
            ->where('vehicle_option_id', $vehicleOptionId)
            ->where('seat_id', $seatId)
            ->whereHas('booking', fn ($query) => $query
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
                ->where('is_join_trip', false))
            ->exists();
    }

    /**
     * คีย์ล็อก: seat_lock:{รอบ}:{คัน}:{ที่นั่ง} — 0 คือรอบที่มีรถคันเดียว
     *
     * รูปแบบเดิมไม่มีช่อง {คัน} ล็อกที่ค้างอยู่ตอนดีพลอยจึงถูกมองข้ามและหมดอายุ
     * ไปเองภายใน TTL ของมัน (สูงสุดราวครึ่งชั่วโมง) การจองซ้ำยังกันได้อยู่ที่
     * unique index ของ booking_seats + lockForUpdate ใน BookingService
     */
    private function seatKey(int $scheduleId, string $seatId, int $vehicleOptionId = 0): string
    {
        return "seat_lock:{$scheduleId}:{$vehicleOptionId}:{$seatId}";
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
