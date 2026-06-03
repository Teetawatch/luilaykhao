<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\TripSchedule;
use App\Services\BookingMemberService;
use Illuminate\Console\Command;

class BackfillBookingMembers extends Command
{
    protected $signature = 'bookings:backfill-members {--dry-run : แสดงผลลัพธ์โดยไม่บันทึก}';

    protected $description = 'ผูก passenger เดิมที่เบอร์/อีเมลตรงกับบัญชีผู้ใช้ เข้าเป็นสมาชิกของการจอง (best-effort)';

    public function handle(BookingMemberService $members): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totalLinked = 0;
        $bookingsTouched = 0;

        Booking::query()
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->with('passengers')
            ->chunkById(100, function ($bookings) use ($members, $dryRun, &$totalLinked, &$bookingsTouched) {
                foreach ($bookings as $booking) {
                    $linked = $members->autoLinkByContact($booking, $dryRun);

                    if ($linked > 0) {
                        $bookingsTouched++;
                        $totalLinked += $linked;
                        $this->line("  {$booking->booking_ref}: ผูก {$linked} คน");
                    }
                }
            });

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}ผูกสมาชิกทั้งหมด {$totalLinked} คน จาก {$bookingsTouched} การจอง");

        return self::SUCCESS;
    }
}
