<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use Illuminate\Console\Command;

/**
 * ซ่อมการจองที่แอดมิน/ลูกค้าเคยเปลี่ยนจุดรับก่อนมีการซิงก์จุดรับรายคน (2026-07-27) —
 * แถว booking_passengers ยังชี้จุดเดิม และหน้าสตาฟ/คนขับอ่านจุดรายคนก่อนเสมอ
 * จึงยังจัดกลุ่มผู้โดยสารเข้าจุดเก่า
 *
 * โหมดปกติแตะเฉพาะเคสที่ชัดเจนว่าค้าง คือ "ไม่มีผู้โดยสารสักคนยืนอยู่จุดของหัวการจอง"
 * และทุกคนอยู่จุดเดียวกัน (จุดเก่า) — การจองที่ผู้โดยสารเลือกจุดกันคนละที่จริง ๆ
 * จะถูกข้ามและรายงานไว้ให้ตรวจเอง ใช้ --all เพื่อบังคับทับทุกคนด้วยจุดของหัวการจอง
 *
 * จุดรับที่ชี้ข้ามรอบ (FK ค้างจากการย้ายรอบ) เป็นหน้าที่ของ bookings:repair-pickups
 */
class SyncPassengerPickupPoints extends Command
{
    protected $signature = 'bookings:sync-passenger-pickups
        {--dry-run : แสดงผลที่จะแก้โดยไม่บันทึก}
        {--all : บังคับให้ผู้โดยสารทุกคนใช้จุดรับของหัวการจอง (ทับจุดที่เลือกรายคน)}
        {--ref=* : เจาะจงเลขที่การจอง เช่น --ref=LLK-20260101-ABCD}
        {--upcoming : เฉพาะรอบที่ยังไม่ออกเดินทาง}';

    protected $description = 'Sync booking_passengers.pickup_point_id with the booking-level pickup point';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $forceAll = $this->option('all');
        $points = SchedulePickupPoint::all()->keyBy('id');

        $query = Booking::query()
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->with(['passengers:id,booking_id,pickup_point_id,name']);

        if ($refs = array_filter($this->option('ref'))) {
            $query->whereIn('booking_ref', $refs);
        }

        if ($this->option('upcoming')) {
            $query->whereHas('schedule', fn ($q) => $q->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString()));
        }

        $synced = 0;
        $passengersFixed = 0;
        $ambiguous = [];

        $query->chunkById(200, function ($bookings) use ($points, $dryRun, $forceAll, &$synced, &$passengersFixed, &$ambiguous) {
            foreach ($bookings as $booking) {
                $target = $this->targetPointId($booking, $points);

                if ($target === false) {
                    continue; // ไม่มีจุดปลายทางที่เชื่อถือได้ — ปล่อยไว้
                }

                $passengers = $booking->passengers;

                if ($passengers->isEmpty()) {
                    continue;
                }

                $current = $passengers
                    ->map(fn ($p) => $p->pickup_point_id ? (int) $p->pickup_point_id : null)
                    ->unique()
                    ->values();

                if ($current->count() === 1 && $current->first() === $target) {
                    continue; // ตรงกันอยู่แล้ว
                }

                // หมุดปักเองเป็นระดับการจอง — จุดตายตัวรายคนอยู่ร่วมไม่ได้ ล้างได้เลย
                if (! $forceAll && $target !== null) {
                    // มีคนยืนจุดของหัวการจองอยู่แล้ว = คนอื่นน่าจะเลือกจุดตัวเองจริง
                    if ($current->contains($target)) {
                        $ambiguous[] = $booking->booking_ref;

                        continue;
                    }

                    // ทุกคนต้องค้างอยู่จุดเดียวกัน (นับ null เป็นพวกเดียวกับหัวการจอง)
                    if ($current->filter(fn ($id) => $id !== null)->count() > 1) {
                        $ambiguous[] = $booking->booking_ref;

                        continue;
                    }
                }

                $stale = $passengers->filter(fn ($p) => ($p->pickup_point_id ? (int) $p->pickup_point_id : null) !== $target);

                $label = $target ? ($points->get($target)?->pickup_location ?? "#{$target}") : 'จุดปักหมุดเอง/ไม่ระบุ';
                $this->line("{$booking->booking_ref}: ผู้โดยสาร {$stale->count()} คน → {$label}");

                if (! $dryRun) {
                    $booking->passengers()
                        ->whereKey($stale->pluck('id')->all())
                        ->update(['pickup_point_id' => $target]);
                }

                $synced++;
                $passengersFixed += $stale->count();
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."ซิงก์การจอง {$synced} รายการ, ผู้โดยสาร {$passengersFixed} คน");

        if ($ambiguous) {
            $this->warn('ข้าม '.count($ambiguous).' รายการที่ผู้โดยสารเลือกจุดรับกันคนละที่ (ตรวจเอง): '.implode(', ', array_slice($ambiguous, 0, 20)).(count($ambiguous) > 20 ? ' ...' : ''));
            $this->line('ถ้าต้องการบังคับให้ทุกคนใช้จุดของหัวการจอง ให้รันซ้ำด้วย --all');
        }

        return self::SUCCESS;
    }

    /**
     * จุดรับที่ผู้โดยสารของการจองนี้ควรอยู่ — null เมื่อการจองใช้หมุดปักเอง,
     * false เมื่อยังตัดสินไม่ได้ (ไม่มีจุด หรือจุดชี้ข้ามรอบ)
     */
    private function targetPointId(Booking $booking, $points): int|false|null
    {
        $point = $booking->pickup_point_id ? $points->get((int) $booking->pickup_point_id) : null;

        if ($point) {
            // จุดข้ามรอบเป็นหน้าที่ของ bookings:repair-pickups
            return (int) $point->schedule_id === (int) $booking->schedule_id ? (int) $point->id : false;
        }

        $hasCustomPickup = $booking->custom_pickup_lat !== null
            && $booking->custom_pickup_lng !== null
            && $booking->custom_pickup_status !== 'rejected';

        return $hasCustomPickup ? null : false;
    }
}
