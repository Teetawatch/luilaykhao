<?php

namespace App\Services;

use App\Events\TripMemberLocationUpdated;
use App\Models\TripMemberLocation;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * "เพื่อนร่วมทริปอยู่ตรงไหน" — ที่เดียวที่ตัดสินว่าใครแชร์ให้ใครเห็นได้เมื่อไหร่
 *
 * พอขึ้นดอยจริงคนกระจายกันเป็นกิโล คำถามที่ดังที่สุดในหัวทุกคนคือ "หัวแถวถึงยัง"
 * กับ "น้องคนนั้นหายไปไหน" ซึ่งเดิมแอปตอบไม่ได้เลย เพราะเห็นแค่รถ ไม่เห็นคน
 *
 * ขอบเขตถูกวางไว้แคบโดยตั้งใจ เพราะนี่คือตำแหน่งของคนจริง:
 *   - แชร์ได้เฉพาะคนในรอบเดียวกัน (นิยามเดียวกับ SOS — [SosParticipantService])
 *   - เฉพาะช่วงทริป (ก่อน 1 วัน ถึงหลังวันกลับ 1 วัน) นอกช่วงนั้นเปิดไม่ได้เลย
 *   - ไม่มีประวัติ เก็บแถวเดียวต่อคน เลิกแชร์ = ลบทิ้ง ไม่มีอะไรค้าง
 *   - หมุดที่เก่ากว่า [STALE_MINUTES] ไม่ถูกส่งออก ดีกว่าวาดหมุดที่โกหก
 *
 * ที่เก็บหลักคือ Redis (hash หนึ่งชุดต่อรอบ field = user id) ไม่ใช่ MySQL: มือถือ
 * ทุกคนที่เปิดแชร์ส่งตำแหน่งทุกครั้งที่ขยับ 15 เมตร ซึ่งมากกว่ารถหลายเท่า และข้อมูล
 * นี้เป็นของชั่วคราวล้วน ๆ — Redis หมดอายุเองเมื่อทั้งรอบเลิกแชร์ ตรงกับ "ไม่มีประวัติ"
 * ตาราง trip_member_locations เหลือไว้เป็นทางสำรองตอน Redis ล่มเท่านั้น
 */
class TripMemberLocationService
{
    /** ตำแหน่งที่เก่ากว่านี้ถือว่าไม่รู้แล้ว (นาที) */
    public const STALE_MINUTES = 30;

    /**
     * อายุของ hash ทั้งรอบใน Redis (วินาที) — ต่ออายุทุกครั้งที่มีคนส่งตำแหน่ง
     * ยาวกว่า [STALE_MINUTES] นิดหน่อย หมุดที่เก่าเกินถูกกรองทิ้งตอนอ่านอยู่แล้ว
     */
    public const REDIS_TTL_SECONDS = (self::STALE_MINUTES + 5) * 60;

    public static function redisKey(int $scheduleId): string
    {
        return "trip-members:{$scheduleId}";
    }

    public function __construct(private SosParticipantService $participants) {}

    /**
     * รอบนี้เปิดแชร์ตำแหน่งได้หรือยัง — ใช้ช่วงเดียวกับ SOS โดยตั้งใจ เพราะมันคือ
     * ช่วงเวลาเดียวกันที่ "รู้ว่าใครอยู่ตรงไหน" มีความหมาย
     */
    public function isWithinWindow(TripSchedule $schedule): bool
    {
        $departure = $schedule->effectiveDepartureDate();
        if (! $departure) {
            return false;
        }

        $today = now('Asia/Bangkok')->toDateString();
        $start = $departure->copy()->subDay()->toDateString();
        $end = ($schedule->return_date ?? $schedule->departure_date)
            ->copy()
            ->addDay()
            ->toDateString();

        return $today >= $start && $today <= $end;
    }

    public function canAccess(TripSchedule $schedule, int $userId): bool
    {
        return $this->participants->includes($schedule, $userId);
    }

    /**
     * บันทึกตำแหน่งล่าสุดของคนหนึ่งคน แล้วบอกคนอื่นในรอบทันทีผ่าน Reverb
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(TripSchedule $schedule, User $user, array $payload): TripMemberLocation
    {
        $attributes = [
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'accuracy_m' => $payload['accuracy_m'] ?? null,
            'heading' => $payload['heading'] ?? null,
            'speed_kmh' => $payload['speed_kmh'] ?? null,
            'altitude_m' => $payload['altitude_m'] ?? null,
            'battery_level' => $payload['battery_level'] ?? null,
            'recorded_at' => now(),
        ];

        // ผ่านโมเดลก่อน เพื่อให้ cast ตัวเลขเหมือนแถวที่อ่านจากฐานข้อมูลทุกอย่าง
        $location = (new TripMemberLocation)->forceFill([
            'schedule_id' => (int) $schedule->id,
            'user_id' => (int) $user->id,
            ...$attributes,
        ]);

        try {
            $key = self::redisKey((int) $schedule->id);
            Redis::hset($key, (string) $user->id, json_encode($this->toStored($location)));
            Redis::expire($key, self::REDIS_TTL_SECONDS);
        } catch (\Throwable) {
            // Redis ล่ม — เขียนลงตารางแทน แล้วอ่านจากตารางจนกว่า Redis จะกลับมา
            $location = TripMemberLocation::updateOrCreate(
                ['schedule_id' => $schedule->id, 'user_id' => $user->id],
                $attributes,
            );
        }

        $location->setRelation('user', $user);

        TripMemberLocationUpdated::dispatch(
            (int) $schedule->id,
            $this->present($location),
        );

        return $location;
    }

    public function stop(TripSchedule $schedule, int $userId): void
    {
        try {
            Redis::hdel(self::redisKey((int) $schedule->id), (string) $userId);
        } catch (\Throwable) {
            // Redis ล่ม — ตอนนั้นการอ่านก็ถอยไปตารางเหมือนกัน ลบแถวข้างล่างก็พอ
        }

        // ลบในตารางด้วยเสมอ — แถวที่เขียนไว้ตอน Redis ล่มต้องไม่ค้างอยู่
        TripMemberLocation::where('schedule_id', $schedule->id)
            ->where('user_id', $userId)
            ->delete();

        TripMemberLocationUpdated::dispatch((int) $schedule->id, [
            'user_id' => $userId,
            'stopped' => true,
        ]);
    }

    /**
     * ทุกคนที่กำลังแชร์อยู่ในรอบนี้ (ไม่รวมคนเรียกเอง — แอปรู้ตำแหน่งตัวเองอยู่แล้ว)
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function members(TripSchedule $schedule, ?int $exceptUserId = null): Collection
    {
        try {
            $locations = $this->membersFromRedis((int) $schedule->id);
        } catch (\Throwable) {
            return $this->membersFromDatabase($schedule, $exceptUserId);
        }

        return $locations
            ->when($exceptUserId, fn ($all) => $all->reject(
                fn (TripMemberLocation $location) => (int) $location->user_id === $exceptUserId,
            ))
            ->map(fn (TripMemberLocation $location) => $this->present($location))
            ->values();
    }

    /**
     * ทุกหมุดที่ยังสดของรอบนี้จาก Redis พร้อมผู้ใช้ (หนึ่ง query) — หมุดที่เก่าเกิน
     * ถูกลบทิ้งไปด้วย คนที่ปิดแอปไปเฉย ๆ จะได้ไม่ค้างอยู่ใน hash จนจบทริป
     *
     * @return Collection<int, TripMemberLocation>
     */
    private function membersFromRedis(int $scheduleId): Collection
    {
        $key = self::redisKey($scheduleId);
        $raw = Redis::hgetall($key);
        $freshFrom = now()->subMinutes(self::STALE_MINUTES);

        $locations = collect();
        $stale = [];

        foreach (is_array($raw) ? $raw : [] as $userId => $json) {
            $location = $this->fromStored($scheduleId, (int) $userId, $json);

            if (! $location || $location->recorded_at->lt($freshFrom)) {
                $stale[] = (string) $userId;

                continue;
            }

            $locations->push($location);
        }

        if ($stale !== []) {
            Redis::hdel($key, ...$stale);
        }

        $users = User::whereIn('id', $locations->pluck('user_id')->all())->get()->keyBy('id');

        return $locations
            ->each(fn (TripMemberLocation $location) => $location->setRelation('user', $users->get($location->user_id)))
            ->sortBy('user_id')
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function membersFromDatabase(TripSchedule $schedule, ?int $exceptUserId): Collection
    {
        return TripMemberLocation::with('user')
            ->where('schedule_id', $schedule->id)
            ->recent(self::STALE_MINUTES)
            ->when($exceptUserId, fn ($query) => $query->where('user_id', '!=', $exceptUserId))
            ->get()
            ->map(fn (TripMemberLocation $location) => $this->present($location))
            ->values();
    }

    /**
     * ค่าที่เก็บลง Redis — ดิบตามคอลัมน์ ไม่มีชื่อ/รูป (อ่านสดจากผู้ใช้ทุกครั้ง)
     *
     * @return array<string, mixed>
     */
    private function toStored(TripMemberLocation $location): array
    {
        return [
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'accuracy_m' => $location->accuracy_m,
            'heading' => $location->heading,
            'speed_kmh' => $location->speed_kmh,
            'altitude_m' => $location->altitude_m,
            'battery_level' => $location->battery_level,
            'recorded_at' => $location->recorded_at?->toIso8601String(),
        ];
    }

    private function fromStored(int $scheduleId, int $userId, mixed $json): ?TripMemberLocation
    {
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! isset($data['latitude'], $data['longitude'], $data['recorded_at'])) {
            return null;
        }

        $location = (new TripMemberLocation)->forceFill([
            'schedule_id' => $scheduleId,
            'user_id' => $userId,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'heading' => $data['heading'] ?? null,
            'speed_kmh' => $data['speed_kmh'] ?? null,
            'altitude_m' => $data['altitude_m'] ?? null,
            'battery_level' => $data['battery_level'] ?? null,
            'recorded_at' => Carbon::parse($data['recorded_at']),
        ]);

        return $location;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TripMemberLocation $location): array
    {
        $user = $location->user;

        return [
            'user_id' => (int) $location->user_id,
            'name' => $user?->nickname ?: ($user?->name ?? 'เพื่อนร่วมทริป'),
            'avatar_url' => $user?->avatar_url,
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'accuracy_m' => $location->accuracy_m,
            'heading' => $location->heading,
            'speed_kmh' => $location->speed_kmh,
            'altitude_m' => $location->altitude_m,
            'battery_level' => $location->battery_level,
            'recorded_at' => $location->recorded_at?->toIso8601String(),
        ];
    }
}
