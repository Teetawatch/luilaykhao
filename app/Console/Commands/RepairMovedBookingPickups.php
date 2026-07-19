<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use Illuminate\Console\Command;

/**
 * ซ่อมการจองที่เคยถูกย้ายรอบ/ข้ามทริปก่อนมีการย้ายจุดรับอัตโนมัติ — แถวเหล่านี้ยังมี
 * pickup_point_id ชี้จุดรับของรอบเดิม ทำให้แอปสตาฟ/คนขับแสดงจุดรับและเวลารับของทริปเดิม
 *
 * จับคู่ไปยังจุดรับชื่อเดียวกันในรอบที่การจองอยู่จริง ถ้าไม่มีจุดตรงกันจะล้าง FK ทิ้ง
 * (คงข้อความ pickup_region ไว้) เพื่อไม่ให้ข้อมูลข้ามรอบหลุดไปแสดง
 */
class RepairMovedBookingPickups extends Command
{
    protected $signature = 'bookings:repair-pickups {--dry-run : แสดงผลที่จะแก้โดยไม่บันทึก}';

    protected $description = 'Repoint bookings/passengers whose pickup point belongs to a different schedule';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $points = SchedulePickupPoint::all()->keyBy('id');
        $pointsBySchedule = $points->groupBy('schedule_id');

        $resolve = function (?int $pointId, int $scheduleId) use ($points, $pointsBySchedule): array {
            $current = $points->get($pointId);

            if (! $current || (int) $current->schedule_id === $scheduleId) {
                return [false, null];
            }

            $match = $pointsBySchedule->get($scheduleId, collect())
                ->firstWhere('pickup_location', $current->pickup_location);

            return [true, $match?->id];
        };

        $fixedBookings = 0;
        $fixedPassengers = 0;

        Booking::whereNotNull('pickup_point_id')->chunkById(200, function ($bookings) use ($resolve, $dryRun, &$fixedBookings) {
            foreach ($bookings as $booking) {
                [$stale, $newPointId] = $resolve($booking->pickup_point_id, (int) $booking->schedule_id);

                if (! $stale) {
                    continue;
                }

                $this->line("การจอง {$booking->booking_ref}: จุดรับ {$booking->pickup_point_id} → ".($newPointId ?? 'ล้างทิ้ง'));

                if (! $dryRun) {
                    $booking->update(['pickup_point_id' => $newPointId]);
                }

                $fixedBookings++;
            }
        });

        BookingPassenger::whereNotNull('pickup_point_id')
            ->with('booking:id,schedule_id,booking_ref')
            ->chunkById(200, function ($passengers) use ($resolve, $dryRun, &$fixedPassengers) {
                foreach ($passengers as $passenger) {
                    if (! $passenger->booking) {
                        continue;
                    }

                    [$stale, $newPointId] = $resolve($passenger->pickup_point_id, (int) $passenger->booking->schedule_id);

                    if (! $stale) {
                        continue;
                    }

                    $this->line("ผู้โดยสาร {$passenger->name} ({$passenger->booking->booking_ref}): จุดรับ {$passenger->pickup_point_id} → ".($newPointId ?? 'ล้างทิ้ง'));

                    if (! $dryRun) {
                        $passenger->update(['pickup_point_id' => $newPointId]);
                    }

                    $fixedPassengers++;
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '')."ซ่อมการจอง {$fixedBookings} รายการ, ผู้โดยสาร {$fixedPassengers} คน");

        return self::SUCCESS;
    }
}
