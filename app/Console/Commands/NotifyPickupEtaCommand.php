<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class NotifyPickupEtaCommand extends Command
{
    protected $signature = 'eta:notify-pickups';

    protected $description = 'Send smart push notifications when the vehicle is approaching each passenger pickup point.';

    /** เตือนเมื่อ ETA ต่ำกว่าค่านี้ (นาที) */
    private const APPROACHING_MINUTES = 20;

    private const ARRIVING_MINUTES = 5;

    /** ถือว่ารถถึงจุดรับแล้วเมื่ออยู่ใกล้กว่าระยะนี้ (กม.) */
    private const ARRIVED_KM = 0.2;

    /** ถือว่าตำแหน่งรถใช้ไม่ได้ถ้าเก่ากว่านี้ (นาที) */
    private const STALE_AFTER_MINUTES = 20;

    public function handle(): int
    {
        $schedules = TripSchedule::with('trip')
            // เทียบกับวันออกรถจริง — รอบที่รถออกคืนก่อนวันทริปจะรับลูกค้าในวันก่อนหน้า
            ->departingOn(today())
            ->whereNotNull('vehicle_id')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->get();

        if ($schedules->isEmpty()) {
            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($schedules as $schedule) {
            $location = $this->vehicleLocation($schedule->vehicle_id);
            if (! $location) {
                continue;
            }

            $bookings = Booking::with('pickupPoint')
                ->where('schedule_id', $schedule->id)
                ->where('status', 'confirmed')
                ->whereNotNull('user_id')
                ->whereNotNull('pickup_point_id')
                ->get();

            foreach ($bookings as $booking) {
                $point = $booking->pickupPoint;
                if (! $point || $point->latitude === null || $point->longitude === null) {
                    continue;
                }

                $distanceKm = $this->distanceKm(
                    (float) $location['latitude'],
                    (float) $location['longitude'],
                    (float) $point->latitude,
                    (float) $point->longitude,
                );

                $minutes = $this->etaMinutes(
                    $distanceKm,
                    isset($location['speed']) ? (float) $location['speed'] : null,
                );

                $stage = $this->stageFor($distanceKm, $minutes);
                if ($stage === null) {
                    continue;
                }

                if ($this->dispatchNotification($booking, $schedule, $point->pickup_location, $minutes, $stage)) {
                    $sent++;
                }
            }
        }

        if ($sent > 0) {
            $this->info("Sent {$sent} pickup ETA notification(s).");
        }

        return self::SUCCESS;
    }

    /**
     * ดึงตำแหน่งล่าสุดของรถจาก Redis และตรวจว่าไม่เก่าเกินไป
     */
    private function vehicleLocation(int $vehicleId): ?array
    {
        try {
            $raw = Redis::get("vehicle:location:{$vehicleId}");
        } catch (\Throwable $e) {
            return null;
        }

        if (! $raw) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : null;
        if ($recordedAt && $recordedAt->diffInMinutes(now()) > self::STALE_AFTER_MINUTES) {
            return null;
        }

        return $data;
    }

    /**
     * คืน stage ที่ควรเตือน หรือ null ถ้ายังไม่ถึงเกณฑ์
     */
    private function stageFor(float $distanceKm, int $minutes): ?string
    {
        if ($distanceKm <= self::ARRIVED_KM) {
            return 'arrived';
        }
        if ($minutes <= self::ARRIVING_MINUTES) {
            return 'arriving';
        }
        if ($minutes <= self::APPROACHING_MINUTES) {
            return 'approaching';
        }

        return null;
    }

    /**
     * ส่ง push หนึ่งครั้งต่อ booking ต่อ stage (กันส่งซ้ำด้วย cache)
     */
    private function dispatchNotification(
        Booking $booking,
        TripSchedule $schedule,
        ?string $pickupName,
        int $minutes,
        string $stage,
    ): bool {
        $cacheKey = "eta_notified:{$booking->id}:{$stage}";
        if (Cache::has($cacheKey)) {
            return false;
        }

        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
        $place = $pickupName ? "จุดรับ $pickupName" : 'จุดรับของคุณ';

        if ($stage === 'arrived') {
            $title = 'รถถึงจุดรับแล้ว! 🚐';
            $body = "รถของทริป \"$tripTitle\" ถึง$place แล้ว กรุณาขึ้นรถได้เลย";
        } elseif ($stage === 'arriving') {
            $title = 'รถกำลังจะถึงแล้ว! 🚐';
            $body = "รถของทริป \"$tripTitle\" จะถึง$place ในอีกประมาณ $minutes นาที กรุณาไปยังจุดรับ";
        } else {
            $title = 'รถใกล้ถึงจุดรับแล้ว 🚐';
            $body = "รถของทริป \"$tripTitle\" จะถึง$place ในอีกประมาณ $minutes นาที เตรียมตัวได้เลย";
        }

        SmartNotification::send(
            $booking->user_id,
            'vehicle_approaching',
            $title,
            $body,
            [
                'booking_ref' => $booking->booking_ref,
                'vehicle_id' => $schedule->vehicle_id,
                'eta_minutes' => $minutes,
                'stage' => $stage,
            ],
        );

        $expiresAt = now()->endOfDay();
        Cache::put($cacheKey, true, $expiresAt);
        // เมื่อแจ้งเตือน stage ที่ใกล้กว่าแล้ว ไม่ต้องเตือน stage ที่ไกลกว่าตามหลังอีก
        if ($stage === 'arrived') {
            Cache::put("eta_notified:{$booking->id}:arriving", true, $expiresAt);
            Cache::put("eta_notified:{$booking->id}:approaching", true, $expiresAt);
        } elseif ($stage === 'arriving') {
            Cache::put("eta_notified:{$booking->id}:approaching", true, $expiresAt);
        }

        return true;
    }

    /**
     * ระยะ haversine ระหว่างสองพิกัด (กม.) — เร็ว ฟรี ไม่เรียก Google API
     */
    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadius = 6371.0; // km
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * ประมาณ ETA จากระยะทางและความเร็ว
     */
    private function etaMinutes(float $distanceKm, ?float $speedKmh): int
    {
        $effectiveSpeed = ($speedKmh !== null && $speedKmh >= 8.0) ? $speedKmh : 35.0;

        return max((int) round(($distanceKm / $effectiveSpeed) * 60), 0);
    }
}
