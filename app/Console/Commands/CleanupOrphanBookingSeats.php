<?php

namespace App\Console\Commands;

use App\Models\BookingSeat;
use App\Models\TripSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupOrphanBookingSeats extends Command
{
    protected $signature = 'seats:cleanup-orphans {--apply : ลบจริง (ค่าเริ่มต้นเป็น dry-run แสดงผลอย่างเดียว)}';

    protected $description = 'ลบแถว booking_seats ที่ค้างอยู่กับการจองที่ยกเลิก/คืนเงินแล้ว (orphan) ซึ่งทำให้ที่นั่งนั้นจองซ้ำไม่ได้ (ชน unique constraint)';

    /**
     * การจองสถานะเหล่านี้ "ปล่อยที่นั่งคืนแล้ว" จึงไม่ควรมีแถว booking_seats เหลือ
     * (ตั้งแต่ fix เป็นต้นไป cancelBooking/processRefund ลบให้อัตโนมัติ — command นี้เก็บกวาดของเก่าก่อน fix)
     */
    private const FREED_STATUSES = ['cancelled', 'refunded'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $orphans = BookingSeat::query()
            ->whereHas('booking', fn ($query) => $query->whereIn('status', self::FREED_STATUSES))
            ->with('booking:id,booking_ref,status')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('ไม่พบ orphan booking_seats — สะอาดดีอยู่แล้ว');

            return self::SUCCESS;
        }

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

        $scheduleIds = $orphans->pluck('schedule_id')->unique()->values();

        if (! $apply) {
            $this->newLine();
            $this->info('[dry-run] ยังไม่ลบ — รันซ้ำด้วย --apply เพื่อลบจริงและ sync จำนวนที่นั่งคืน');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($orphans, $scheduleIds) {
            BookingSeat::whereKey($orphans->pluck('id'))->delete();

            // ปรับตัวนับ booked_seats ของรอบที่ได้รับผลกระทบให้ตรงกับความจริง
            TripSchedule::whereIn('id', $scheduleIds)
                ->get()
                ->each
                ->syncBookedSeats();
        });

        $this->newLine();
        $this->info("ลบ orphan {$orphans->count()} แถว และ sync ที่นั่งคืนใน {$scheduleIds->count()} รอบเรียบร้อย");

        return self::SUCCESS;
    }
}
