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

    /**
     * เคยส่งแล้วแต่เงียบไปนานกว่านี้ ถือว่าการแชร์หลุด (นาที)
     *
     * ยาวกว่าตัวบนตั้งใจ: ระหว่างทางมีทั้งอุโมงค์ ทางเขา และเสาสัญญาณห่าง การ
     * เงียบยี่สิบนาทีเป็นเรื่องปกติ ส่วนสี่สิบห้านาทีมักแปลว่าเครื่องรีสตาร์ตหรือ
     * แอปถูกระบบปิดไปแล้ว
     */
    public const STALLED_MINUTES = 45;

    /** หลังรถออกกี่ชั่วโมงยังคุ้มที่จะเตือนเรื่องการแชร์ที่หลุดกลางทาง */
    private const STALLED_WINDOW_HOURS = 10;

    public function handle(): void
    {
        $now = now('Asia/Bangkok');
        // เวลาไทยในกรอบเดียวกับที่ departs_at/departure_date ถูกเก็บ (ตัวเลขนาฬิกา
        // ไทยในคอลัมน์ชนิด UTC) — เทียบกับ now('Asia/Bangkok') ตรง ๆ จะเพี้ยน 7 ชม.
        $nowThai = Carbon::parse($now->format('Y-m-d H:i:s'));
        $sent = 0;

        $schedules = TripSchedule::query()
            ->departingOn($now)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('vehicle_id')
            ->with(['trip', 'vehicle', 'activeStaff'])
            ->get();

        foreach ($schedules as $schedule) {
            $slot = $this->slotFor($schedule, $nowThai);

            if ($slot === null) {
                continue;
            }

            $plate = trim((string) $schedule->vehicle?->license_plate);
            $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

            [$title, $body] = $slot === 'stalled'
                ? [
                    "📍 {$tripTitle} — ตำแหน่งรถหยุดส่ง",
                    "แอปไม่ได้ส่งตำแหน่งรถ{$this->plateSuffix($plate)}มาสักพักแล้ว "
                        .'เปิดแชร์อีกครั้งเพื่อให้ลูกค้าและคนที่บ้านเห็นว่ารถถึงไหน',
                ]
                : [
                    "📍 {$tripTitle} — อย่าลืมเปิดแชร์ตำแหน่งรถ",
                    "เปิดแชร์ตำแหน่งรถ{$this->plateSuffix($plate)}ในแอป ลูกค้าจะได้เห็นว่ารถถึงไหนแล้ว "
                        .'และการ์ดวันเดินทางบนเครื่องลูกค้าจะเดินต่อได้',
                ];

            foreach ($schedule->activeStaff as $staff) {
                if ($this->alreadySent($staff->id, $schedule->id, $now, $slot)) {
                    continue;
                }

                SmartNotification::send(
                    $staff->id,
                    'staff_share_location',
                    $title,
                    $body,
                    [
                        'route' => 'staff_manifest',
                        'schedule_id' => (string) $schedule->id,
                        // หน้ารายชื่อของสตาฟเปิดมาพร้อมชื่อทริปบนหัวจอ
                        'trip_title' => $tripTitle,
                        'on' => $now->toDateString(),
                        'slot' => $slot,
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

    /**
     * ใกล้เวลารถออกพอที่จะเตือนหรือยัง — รอบที่ไม่ได้กรอกเวลาใช้ 06:00 เป็นตัวแทน
     *
     * $now ต้องเป็นเวลาไทยในกรอบเดียวกับคอลัมน์ (ดู handle) — เดิมรอบที่ไม่มี
     * departs_at ถูกคิดเป็น 06:00 UTC = 13:00 ไทย การเตือนจึงดังหลังรถออกครึ่งวัน
     */
    private function slotFor(TripSchedule $schedule, Carbon $now): ?string
    {
        $departsAt = $schedule->departs_at?->copy()
            ?: $schedule->departure_date?->copy()->setTime(6, 0);

        if (! $departsAt) {
            return null;
        }

        $vehicleId = (int) $schedule->vehicle_id;

        // ก่อนรถออก: ยังไม่มีพิกัดเลย = ยังไม่มีใครเปิด
        if ($now->gte($departsAt->copy()->subMinutes(self::LEAD_MINUTES))
            && $now->lte($departsAt->copy()->addHours(2))
            && ! $this->hasLocationWithin($vehicleId, self::STALE_MINUTES)) {
            return 'before';
        }

        // ระหว่างทาง: เคยส่งแล้ววันนี้แต่เงียบไปนาน = การแชร์หลุด (เครื่องรีสตาร์ต
        // แอปถูกปิด หรือเผลอกดปิด) ไม่เตือนรอบที่ไม่เคยเปิดเลย เพราะเตือนไปแล้ว
        if ($now->gt($departsAt->copy()->addMinutes(30))
            && $now->lte($departsAt->copy()->addHours(self::STALLED_WINDOW_HOURS))
            && ! $this->hasLocationWithin($vehicleId, self::STALLED_MINUTES)
            && $this->sharedEarlierToday($vehicleId)) {
            return 'stalled';
        }

        return null;
    }

    private function hasLocationWithin(int $vehicleId, int $minutes): bool
    {
        return VehicleLocation::where('vehicle_id', $vehicleId)
            ->where('recorded_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * วันนี้เคยมีพิกัดเข้ามาไหม — พิสูจน์ว่าสตาฟเปิดแชร์ไปแล้วจริง
     *
     * ต้องย้ายเขตเวลาก่อนเทียบ: ส่ง Carbon เขตไทยเข้า query ตรง ๆ Laravel จะ
     * format ตามเขตของมันเอง กลายเป็นเทียบกับเที่ยงคืน "UTC" ซึ่งคือเจ็ดโมงเช้า
     * บ้านเรา — พิกัดของรถที่ออกตีห้าจึงไม่ถูกนับว่า "เคยแชร์วันนี้" เลยสักคัน
     */
    private function sharedEarlierToday(int $vehicleId): bool
    {
        $startOfDay = now('Asia/Bangkok')
            ->startOfDay()
            ->setTimezone(config('app.timezone', 'UTC'));

        return VehicleLocation::where('vehicle_id', $vehicleId)
            ->where('recorded_at', '>=', $startOfDay)
            ->exists();
    }

    private function alreadySent(int $staffId, int $scheduleId, Carbon $now, string $slot): bool
    {
        return SmartNotification::where('user_id', $staffId)
            ->where('type', 'staff_share_location')
            ->where('data->schedule_id', (string) $scheduleId)
            ->where('data->on', $now->toDateString())
            ->where('data->slot', $slot)
            ->exists();
    }
}
