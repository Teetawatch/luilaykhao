<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\TripSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CleanupOrphanBookingSeats extends Command
{
    protected $signature = 'seats:cleanup-orphans {--apply : ลบจริง (ค่าเริ่มต้นเป็น dry-run แสดงผลอย่างเดียว)}';

    protected $description = 'ลบแถว booking_seats ที่ค้างอยู่ — ทั้งของการจองที่ยกเลิก/คืนเงินแล้ว และที่นั่งส่วนเกินจำนวนผู้โดยสาร ซึ่งทำให้ที่นั่งนั้นจองซ้ำไม่ได้ (ชน unique constraint)';

    /**
     * การจองสถานะเหล่านี้ "ปล่อยที่นั่งคืนแล้ว" จึงไม่ควรมีแถว booking_seats เหลือ
     * (ตั้งแต่ fix เป็นต้นไป cancelBooking/processRefund ลบให้อัตโนมัติ — command นี้เก็บกวาดของเก่าก่อน fix)
     */
    private const FREED_STATUSES = ['cancelled', 'refunded'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $orphans = $this->orphanSeats();
        $surplus = $this->surplusSeats();
        $total = $orphans->count() + $surplus->count();

        if ($total === 0) {
            $this->info('ไม่พบ booking_seats ที่ค้าง — สะอาดดีอยู่แล้ว');

            return self::SUCCESS;
        }

        if ($orphans->isNotEmpty()) {
            $this->warn("พบ orphan {$orphans->count()} แถว (ผูกกับการจองที่ยกเลิก/คืนเงินแล้ว):");
            foreach ($orphans as $seat) {
                $this->line(sprintf(
                    '  schedule %d seat %s | bk=%d %s | %s',
                    $seat->schedule_id,
                    $seat->seat_id,
                    $seat->booking_id,
                    $seat->booking?->booking_ref ?? '-',
                    $seat->booking?->status ?? 'NULL',
                ));
            }
        }

        if ($surplus->isNotEmpty()) {
            $this->warn("พบที่นั่งส่วนเกิน {$surplus->count()} แถว (การจองยัง active แต่ที่นั่งมากกว่าผู้โดยสาร):");
            foreach ($surplus as $seat) {
                $this->line(sprintf(
                    '  schedule %d seat %s | bk=%d %s | %s',
                    $seat->schedule_id,
                    $seat->seat_id,
                    $seat->booking_id,
                    $seat->booking?->booking_ref ?? '-',
                    $seat->booking?->status ?? 'NULL',
                ));
            }
        }

        $stale = $orphans->concat($surplus);
        $scheduleIds = $stale->pluck('schedule_id')->unique()->values();

        if (! $apply) {
            $this->newLine();
            $this->info('[dry-run] ยังไม่ลบ — รันซ้ำด้วย --apply เพื่อลบจริงและ sync จำนวนที่นั่งคืน');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($stale, $scheduleIds) {
            BookingSeat::whereKey($stale->pluck('id'))->delete();

            // ปรับตัวนับ booked_seats ของรอบที่ได้รับผลกระทบให้ตรงกับความจริง
            TripSchedule::whereIn('id', $scheduleIds)
                ->get()
                ->each
                ->syncBookedSeats();
        });

        $this->newLine();
        $this->info("ลบที่นั่งค้าง {$stale->count()} แถว และ sync ที่นั่งคืนใน {$scheduleIds->count()} รอบเรียบร้อย");

        return self::SUCCESS;
    }

    /**
     * ที่นั่งของการจองที่ยกเลิก/คืนเงินแล้ว — ควรถูกปล่อยคืนทั้งหมด
     */
    private function orphanSeats(): Collection
    {
        return BookingSeat::query()
            ->whereHas('booking', fn ($query) => $query->whereIn('status', self::FREED_STATUSES))
            ->with('booking:id,booking_ref,status')
            ->get();
    }

    /**
     * ที่นั่งส่วนเกินของการจองที่ยัง active — เกิดจากการลบผู้โดยสารแล้วแถวที่นั่งค้างไว้
     * ที่นั่งกับผู้โดยสารเป็น 1:1 จึงตัดส่วนที่เกินจากท้ายรายการ (เรียงตาม id เหมือนตอนสร้าง)
     */
    private function surplusSeats(): Collection
    {
        return Booking::query()
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->where('is_join_trip', false)
            ->withCount('passengers')
            ->with('seats')
            ->has('seats')
            ->get()
            ->flatMap(function (Booking $booking) {
                $keep = $booking->passengers_count;

                // ไม่มีผู้โดยสารเลย = ข้อมูลยังกรอกไม่ครบ ไม่ใช่การลบ จึงไม่แตะที่นั่ง
                if ($keep === 0 || $booking->seats->count() <= $keep) {
                    return [];
                }

                return $booking->seats
                    ->sortBy('id')
                    ->slice($keep)
                    ->each(fn (BookingSeat $seat) => $seat->setRelation('booking', $booking));
            })
            ->values();
    }
}
