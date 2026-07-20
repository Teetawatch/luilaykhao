<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\User;

/**
 * "ทริปนี้ไหวไหม" — เทียบความหนักของทริปกับสิ่งที่ผู้ใช้เคยเดินมาจริง
 *
 * ประวัติมาจาก Passport (ทริปที่เดินทางจบแล้ว) ถ้ายังไม่เคยไปกับเราเลย จะใช้ค่า
 * ที่ผู้ใช้กรอกเองแทน และถ้าไม่มีทั้งสองอย่างก็จะบอกตรง ๆ ว่ายังประเมินให้ไม่ได้
 * แทนที่จะเดาแล้วให้คำตอบผิด
 */
class TripReadinessService
{
    /** ทริปหนักกว่าประวัติเกินเท่านี้ = เกินตัว (เท่า) */
    public const BEYOND_RATIO = 1.8;

    /** ทริปหนักกว่าประวัติเกินเท่านี้ = ท้าทาย แต่ยังพอไหว (เท่า) */
    public const STRETCH_RATIO = 1.15;

    public const VERDICT_UNKNOWN = 'unknown';

    public const VERDICT_COMFORTABLE = 'comfortable';

    public const VERDICT_STRETCH = 'stretch';

    public const VERDICT_BEYOND = 'beyond';

    public function __construct(
        private PassportService $passport,
    ) {}

    /**
     * ประเมินความพร้อมของผู้ใช้ต่อทริปหนึ่ง
     *
     * @return array<string, mixed>
     */
    public function evaluate(Trip $trip, ?User $user): array
    {
        $tripDistance = (float) ($trip->distance_km ?? 0);
        $tripElevation = (int) ($trip->elevation_gain_m ?? 0);

        // ทริปที่ยังไม่ได้กรอกระยะทาง/ความสูง เทียบอะไรไม่ได้เลย
        if ($tripDistance <= 0 && $tripElevation <= 0) {
            return $this->unavailable('trip_data_missing', $trip);
        }

        if (! $user) {
            return $this->unavailable('not_logged_in', $trip);
        }

        $baseline = $this->baselineFor($user);

        if (! $baseline['has_data']) {
            return $this->unavailable('no_baseline', $trip);
        }

        $distanceRatio = $this->ratio($tripDistance, $baseline['max_distance_km']);
        $elevationRatio = $this->ratio($tripElevation, $baseline['max_elevation_gain_m']);

        // ตัดสินจากด้านที่หนักที่สุด — ถ้าระยะทางไหวแต่ความชันไม่ไหว ก็คือไม่ไหว
        $worstRatio = max(array_filter([$distanceRatio, $elevationRatio], fn ($r) => $r !== null) ?: [0]);

        return [
            'available' => true,
            'verdict' => $this->verdictFor($worstRatio),
            'source' => $baseline['source'],
            'trip' => [
                'distance_km' => $tripDistance ?: null,
                'elevation_gain_m' => $tripElevation ?: null,
                'duration_days' => $trip->duration_days,
            ],
            'you' => [
                'max_distance_km' => $baseline['max_distance_km'],
                'max_elevation_gain_m' => $baseline['max_elevation_gain_m'],
                'trips_count' => $baseline['trips_count'],
            ],
            'comparison' => [
                'distance_ratio' => $distanceRatio,
                'elevation_ratio' => $elevationRatio,
                'hardest_ratio' => round($worstRatio, 2),
            ],
            'message' => $this->messageFor($this->verdictFor($worstRatio), $worstRatio),
        ];
    }

    /**
     * ทริปที่เบากว่า ไว้แนะนำเมื่อทริปที่ดูอยู่หนักเกินตัว
     *
     * @return array<int, array<string, mixed>>
     */
    public function easierAlternatives(Trip $trip, ?User $user, int $limit = 3): array
    {
        $evaluation = $this->evaluate($trip, $user);

        if (! ($evaluation['available'] ?? false) || $evaluation['verdict'] !== self::VERDICT_BEYOND) {
            return [];
        }

        $maxDistance = $evaluation['you']['max_distance_km'];
        $maxElevation = $evaluation['you']['max_elevation_gain_m'];

        return Trip::query()
            ->where('status', 'active')
            ->where('id', '!=', $trip->id)
            ->where('type', $trip->type)
            ->where(function ($q) {
                // ต้องมีตัวเลขให้เทียบ ไม่งั้นแนะนำไปก็ไม่รู้ว่าเบากว่าจริงไหม
                $q->where('distance_km', '>', 0)->orWhere('elevation_gain_m', '>', 0);
            })
            // เบากว่าทริปที่ดูอยู่ และไม่เกินตัวผู้ใช้
            ->when($maxDistance > 0, fn ($q) => $q->where(function ($inner) use ($maxDistance) {
                $inner->whereNull('distance_km')
                    ->orWhere('distance_km', '<=', $maxDistance * self::BEYOND_RATIO);
            }))
            ->when($maxElevation > 0, fn ($q) => $q->where(function ($inner) use ($maxElevation) {
                $inner->whereNull('elevation_gain_m')
                    ->orWhere('elevation_gain_m', '<=', $maxElevation * self::BEYOND_RATIO);
            }))
            ->orderBy('distance_km')
            ->limit($limit)
            ->get()
            ->map(fn (Trip $t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'title' => $t->title,
                'distance_km' => $t->distance_km !== null ? (float) $t->distance_km : null,
                'elevation_gain_m' => $t->elevation_gain_m,
                'cover_image' => $t->cover_image,
            ])
            ->all();
    }

    /**
     * สถิติสูงสุดที่ผู้ใช้เคยทำได้ — ใช้ประวัติจริงก่อน ถ้าไม่มีค่อยใช้ค่าที่กรอกเอง
     *
     * @return array{has_data: bool, source: string, max_distance_km: float, max_elevation_gain_m: int, trips_count: int}
     */
    public function baselineFor(User $user): array
    {
        $history = $this->passport->forUser($user->id);
        $tripsCount = (int) ($history['stats']['trips_count'] ?? 0);

        if ($tripsCount > 0) {
            $hardest = $this->hardestCompleted($user);

            return [
                'has_data' => true,
                'source' => 'history',
                'max_distance_km' => $hardest['distance'],
                'max_elevation_gain_m' => $hardest['elevation'],
                'trips_count' => $tripsCount,
            ];
        }

        $selfDistance = (float) ($user->self_reported_max_distance_km ?? 0);
        $selfElevation = (int) ($user->self_reported_max_elevation_m ?? 0);

        if ($selfDistance > 0 || $selfElevation > 0) {
            return [
                'has_data' => true,
                'source' => 'self_reported',
                'max_distance_km' => $selfDistance,
                'max_elevation_gain_m' => $selfElevation,
                'trips_count' => 0,
            ];
        }

        return [
            'has_data' => false,
            'source' => 'none',
            'max_distance_km' => 0.0,
            'max_elevation_gain_m' => 0,
            'trips_count' => 0,
        ];
    }

    /**
     * ทริปที่หนักที่สุดที่ผู้ใช้เคยเดินจบ — เทียบกับ "ที่เคยทำได้สูงสุด" ไม่ใช่ค่าเฉลี่ย
     * เพราะคนที่เคยขึ้นอินทนนท์แล้วย่อมไหวทริปเล็ก ๆ แม้จะไปทริปเบา ๆ มาสิบครั้ง
     *
     * @return array{distance: float, elevation: int}
     */
    private function hardestCompleted(User $user): array
    {
        $trips = $this->passport->completedTripsFor($user->id);

        $distance = 0.0;
        $elevation = 0;

        foreach ($trips as $entry) {
            $trip = $entry['trip'];
            $distance = max($distance, (float) ($trip->distance_km ?? 0));
            $elevation = max($elevation, (int) ($trip->elevation_gain_m ?? 0));
        }

        return ['distance' => round($distance, 1), 'elevation' => $elevation];
    }

    /** อัตราส่วนความหนักของทริปเทียบกับสถิติผู้ใช้ (null เมื่อเทียบไม่ได้) */
    private function ratio(float $tripValue, float $userMax): ?float
    {
        if ($tripValue <= 0) {
            return null;
        }

        // ทริปมีตัวเลขแต่ผู้ใช้ไม่มีสถิติด้านนี้ → ถือว่าเกินตัวไว้ก่อน ปลอดภัยกว่าเดาว่าไหว
        if ($userMax <= 0) {
            return self::BEYOND_RATIO;
        }

        return round($tripValue / $userMax, 2);
    }

    private function verdictFor(float $ratio): string
    {
        if ($ratio >= self::BEYOND_RATIO) {
            return self::VERDICT_BEYOND;
        }

        if ($ratio >= self::STRETCH_RATIO) {
            return self::VERDICT_STRETCH;
        }

        return self::VERDICT_COMFORTABLE;
    }

    private function messageFor(string $verdict, float $ratio): string
    {
        return match ($verdict) {
            self::VERDICT_COMFORTABLE => 'ทริปนี้อยู่ในระดับที่คุณเคยเดินมาแล้ว น่าจะไปได้สบาย',
            self::VERDICT_STRETCH => 'ทริปนี้หนักกว่าที่คุณเคยเดินอยู่บ้าง เตรียมร่างกายสักหน่อยจะสนุกกว่าเดิม',
            self::VERDICT_BEYOND => sprintf(
                'ทริปนี้หนักกว่าที่คุณเคยเดินมาราว %s เท่า แนะนำให้ซ้อมก่อน หรือลองทริปที่เบากว่านี้ดูก่อน',
                $ratio >= 10 ? round($ratio) : round($ratio, 1),
            ),
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason, Trip $trip): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'verdict' => self::VERDICT_UNKNOWN,
            'trip' => [
                'distance_km' => $trip->distance_km !== null ? (float) $trip->distance_km : null,
                'elevation_gain_m' => $trip->elevation_gain_m,
                'duration_days' => $trip->duration_days,
            ],
            'message' => match ($reason) {
                'trip_data_missing' => 'ทริปนี้ยังไม่ได้ระบุระยะทางและความสูง จึงยังเทียบให้ไม่ได้',
                'not_logged_in' => 'เข้าสู่ระบบเพื่อดูว่าทริปนี้เหมาะกับคุณแค่ไหน',
                'no_baseline' => 'บอกเราหน่อยว่าคุณเคยเดินไกลสุดเท่าไหร่ แล้วเราจะเทียบให้',
                default => '',
            },
        ];
    }
}
