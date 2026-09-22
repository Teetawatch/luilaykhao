<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\VehicleLocation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * เตือนสตาฟให้เปิดแชร์ตำแหน่งรถ ก่อนรถออกไม่นาน
 *
 * คนขับไม่ได้ใช้แอป มือถือของสตาฟที่นั่งไปกับรถจึงเป็น GPS ของรถ ถ้าไม่มีใคร
 * เปิด ลูกค้าจะไม่เห็นหมุดรถบนแผนที่ ไม่ได้ ETA และการ์ดบนหน้าจอล็อกจะค้างอยู่
 * ที่ "เตรียมตัว" ทั้งวัน — ทั้งหมดนี้พังเงียบ ๆ โดยไม่มีใครรู้จนถึงหน้างาน
 *
 * เตือนเฉพาะรอบที่ "ควรมีตำแหน่งแล้วแต่ยังไม่มี" — รอบที่สตาฟเปิดแชร์อยู่แล้ว
 * จะไม่ได้รับอะไรเลย การเตือนที่ดังทั้งที่ทำถูกแล้วคือการเตือนที่คนจะเลิกอ่าน
 */
class RemindStaffToShareLocationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** เตือนเมื่อเหลือเวลาถึงรถออกไม่เกินกี่นาที */
    public const LEAD_MINUTES = 90;

    /** พิกัดเก่ากว่านี้ถือว่ายังไม่ได้เปิดแชร์ (นาที) */
    public const STALE_MINUTES = 20;

    public function handle(): void
    {
        $now = now('Asia/Bangkok');
        $sent = 0;

        $schedules = TripSchedule::query()
            ->departingOn($now)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('vehicle_id')
            ->with(['trip', 'vehicle', 'activeStaff'])
            ->get();

        foreach ($schedules as $schedule) {
            if (! $this->dueFor($schedule, $now)) {
                continue;
            }

            if ($this->hasFreshLocation((int) $schedule->vehicle_id)) {
                continue;
            }

            $plate = trim((string) $schedule->vehicle?->license_plate);
            $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
            $body = "เปิดแชร์ตำแหน่งรถ{$this->plateSuffix($plate)}ในแอป ลูกค้าจะได้เห็นว่ารถถึงไหนแล้ว "
                .'และการ์ดวันเดินทางบนเครื่องลูกค้าจะเดินต่อได้';

            foreach ($schedule->activeStaff as $staff) {
                if ($this->alreadySent($staff->id, $schedule->id, $now)) {
                    continue;
                }

                SmartNotification::send(
                    $staff->id,
                    'staff_share_location',
                    "📍 {$tripTitle} — อย่าลืมเปิดแชร์ตำแหน่งรถ",
                    $body,
                    [
                        'route' => 'staff_manifest',
                        'schedule_id' => (string) $schedule->id,
                        'on' => $now->toDateString(),
                    ],
                );

                $sent++;
            }
        }

        if ($sent > 0) {
            Log::info('RemindStaffToShareLocationJob completed', ['sent' => $sent]);
        }
    }

    private function plateSuffix(string $plate): string
    {
        return $plate !== '' ? " (ทะเบียน {$plate})" : '';
    }

    /** ใกล้เวลารถออกพอที่จะเตือนหรือยัง — รอบที่ไม่ได้กรอกเวลาใช้ 06:00 เป็นตัวแทน */
    private function dueFor(TripSchedule $schedule, Carbon $now): bool
    {
        $departsAt = $schedule->departs_at
            ? Carbon::parse($schedule->departs_at->format('Y-m-d H:i:s'), 'Asia/Bangkok')
            : $schedule->departure_date?->copy()->setTime(6, 0);

        if (! $departsAt) {
            return false;
        }

        // ยังไม่ถึงเวลาเตือน หรือเลยเวลารถออกไปนานแล้ว (สายไปที่จะเตือน)
        return $now->lte($departsAt->copy()->addHours(2))
            && $now->gte($departsAt->copy()->subMinutes(self::LEAD_MINUTES));
    }

    private function hasFreshLocation(int $vehicleId): bool
    {
        return VehicleLocation::where('vehicle_id', $vehicleId)
            ->where('recorded_at', '>=', now()->subMinutes(self::STALE_MINUTES))
            ->exists();
    }

    private function alreadySent(int $staffId, int $scheduleId, Carbon $now): bool
    {
        return SmartNotification::where('user_id', $staffId)
            ->where('type', 'staff_share_location')
            ->where('data->schedule_id', (string) $scheduleId)
            ->where('data->on', $now->toDateString())
            ->exists();
    }
}
