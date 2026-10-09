<?php

namespace App\Console\Commands;

use App\Models\BookingSeat;
use App\Services\SeatLayoutGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ตรวจรอบที่ยังไม่จบว่ามีที่นั่งที่ขายแล้วแต่ไม่อยู่บนผังไหม — อ่านอย่างเดียว
 *
 * SeatLayoutGuard กันไม่ให้เกิดใหม่จากทุกทางที่รู้จักแล้ว ตัวนี้มีไว้จับทางที่
 * ยังไม่รู้จัก (เช่น แก้ข้อมูลตรงในฐานข้อมูล) ก่อนลูกค้าหรือสตาฟเจอเองหน้างาน
 */
class AuditSeatLayouts extends Command
{
    protected $signature = 'seats:audit';

    protected $description = 'หารอบที่ยังไม่จบซึ่งมีที่นั่งของใบจองที่ไม่อยู่บนผังที่นั่ง (ผังโชว์ว่างทั้งที่รอบเต็ม / ที่นั่งไปตกผิดตำแหน่ง)';

    public function handle(SeatLayoutGuard $guard): int
    {
        $conflicts = $guard->upcomingConflicts();

        if ($conflicts->isEmpty()) {
            $this->info('ที่นั่งของทุกรอบที่ยังไม่จบอยู่บนผังครบ');

            return self::SUCCESS;
        }

        foreach ($conflicts as ['schedule' => $schedule, 'orphans' => $orphans]) {
            $this->warn(sprintf(
                '#%d %s %s — หลุดผัง %d ที่: %s',
                $schedule->id,
                $schedule->departure_date?->toDateString(),
                $schedule->trip?->title ?? '',
                $orphans->count(),
                $orphans->map(fn (BookingSeat $seat) => $seat->seat_id.' ('.($seat->booking?->booking_ref ?? '#'.$seat->booking_id).')')->join(', '),
            ));
        }

        Log::warning('seats:audit found seats missing from the seat map', [
            'schedules' => $conflicts->map(fn (array $row) => [
                'schedule_id' => $row['schedule']->id,
                'seat_ids' => $row['orphans']->pluck('seat_id')->all(),
            ])->all(),
        ]);

        return self::FAILURE;
    }
}
