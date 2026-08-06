<?php

namespace App\Services;

use App\Events\TripMemberLocationUpdated;
use App\Models\TripMemberLocation;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

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
 */
class TripMemberLocationService
{
    /** ตำแหน่งที่เก่ากว่านี้ถือว่าไม่รู้แล้ว (นาที) */
    public const STALE_MINUTES = 30;

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
        $location = TripMemberLocation::updateOrCreate(
            ['schedule_id' => $schedule->id, 'user_id' => $user->id],
            [
                'latitude' => $payload['latitude'],
                'longitude' => $payload['longitude'],
                'accuracy_m' => $payload['accuracy_m'] ?? null,
                'heading' => $payload['heading'] ?? null,
                'speed_kmh' => $payload['speed_kmh'] ?? null,
                'altitude_m' => $payload['altitude_m'] ?? null,
                'battery_level' => $payload['battery_level'] ?? null,
                'recorded_at' => now(),
            ],
        );

        $location->setRelation('user', $user);

        TripMemberLocationUpdated::dispatch(
            (int) $schedule->id,
            $this->present($location),
        );

        return $location;
    }

    public function stop(TripSchedule $schedule, int $userId): void
    {
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
        return TripMemberLocation::with('user')
            ->where('schedule_id', $schedule->id)
            ->recent(self::STALE_MINUTES)
            ->when($exceptUserId, fn ($query) => $query->where('user_id', '!=', $exceptUserId))
            ->get()
            ->map(fn (TripMemberLocation $location) => $this->present($location))
            ->values();
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
