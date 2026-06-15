<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\TripSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Mint per-booking "fill in everyone's birth date" links for upcoming trips that
 * still have passengers without a birth date, and export them to CSV. Covers the
 * "booked on behalf of friends" case — one link per booking fills the whole group.
 */
class GenerateBookingBirthdateLinks extends Command
{
    protected $signature = 'birthdate:booking-links';

    protected $description = 'Generate per-booking birth-date links for upcoming trips with missing birth dates (CSV).';

    public function handle(): int
    {
        $bookings = Booking::query()
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->whereHas('schedule', fn ($s) => $s->whereDate('departure_date', '>=', now()->toDateString()))
            ->whereHas('passengers', fn ($p) => $p->whereNull('birth_date'))
            ->with(['user', 'schedule.trip', 'passengers'])
            ->orderBy('id')
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('ไม่มีการจองที่ยังขาดวันเกิดในทริปที่กำลังจะถึง');

            return self::SUCCESS;
        }

        $lines = ['"booking_ref","คนจอง","เบอร์โทร","ทริป","วันเดินทาง","ขาดวันเกิด(คน)","ลิงก์กรอกวันเกิด"'];
        foreach ($bookings as $booking) {
            $missing = $booking->passengers->whereNull('birth_date')->count();
            $row = [
                $booking->booking_ref,
                $booking->user?->name,
                $booking->user?->phone,
                $booking->schedule?->trip?->title,
                $booking->schedule?->departure_date?->format('Y-m-d'),
                $missing,
                $booking->birthdateUrl(),
            ];
            $lines[] = collect($row)
                ->map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"')
                ->implode(',');
        }

        $path = 'booking-birthdate-links-'.now()->format('Ymd_His').'.csv';
        Storage::put($path, "\u{FEFF}".implode("\n", $lines));

        $this->info('สร้างลิงก์ให้การจอง '.$bookings->count().' รายการ');
        $this->line('ไฟล์ CSV: '.Storage::path($path));

        return self::SUCCESS;
    }
}
