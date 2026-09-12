<?php

namespace App\Console\Commands;

use App\Jobs\SendTripBriefsJob;
use App\Models\Booking;
use App\Services\TripBriefService;
use Illuminate\Console\Command;

/**
 * รันงานส่ง "ใบเดินทาง" ด้วยมือ + ดูก่อนว่ารอบไหนจะได้อะไร
 *
 * ตัวงานจริงวิ่งเองทุกวัน 18:05 (routes/console.php) คำสั่งนี้มีไว้สองอย่าง:
 * ตรวจก่อนปล่อยของจริง (--dry-run) และสั่งส่งซ้ำเองเมื่อทีมงานเพิ่งกรอกข้อมูลรถ
 * เสร็จแล้วไม่อยากรอถึงพรุ่งนี้
 */
class SendTripBriefs extends Command
{
    protected $signature = 'trip-briefs:send
        {--dry-run : แสดงว่าใครจะได้ใบเดินทางทางช่องไหน โดยไม่ส่งจริง}';

    protected $description = 'ส่งใบเดินทางให้ลูกค้าที่กำลังจะเดินทางในอีก 2 วัน (อีเมล หรือ SMS เมื่อไม่มีอีเมลจริง)';

    public function handle(TripBriefService $briefs): int
    {
        if ($this->option('dry-run')) {
            return $this->preview($briefs);
        }

        $job = new SendTripBriefsJob;
        app()->call([$job, 'handle']);

        $this->info(sprintf(
            'ส่งใหม่ %d · อัปเดต %d · รอข้อมูลรถ/ทีมงาน %d · ข้าม %d',
            $job->summary['sent'],
            $job->summary['updated'],
            $job->summary['waiting'],
            $job->summary['skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * ดูก่อนว่ารอบที่กำลังจะถึงใครจะได้ใบเดินทางทางช่องไหน — แถวที่ขึ้นว่า "ไม่มี
     * ช่องทาง" คือใบจองที่ต้องตามเก็บเบอร์หรืออีเมลก่อนถึงวันเดินทาง
     */
    private function preview(TripBriefService $briefs): int
    {
        $bookings = Booking::query()
            ->whereIn('status', TripBriefService::ELIGIBLE_STATUSES)
            ->whereHas('schedule', fn ($q) => $q
                ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
                ->whereDate('departure_date', '<=', now('Asia/Bangkok')->addDays(SendTripBriefsJob::LEAD_DAYS)->toDateString())
                ->where('status', '!=', 'cancelled'))
            ->with($briefs->relations())
            ->orderBy('id')
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('ไม่มีรอบที่ถึงคิวส่งใบเดินทางในช่วงนี้');

            return self::SUCCESS;
        }

        $rows = $bookings->map(function (Booking $booking) use ($briefs) {
            $emails = $booking->passengers->pluck('email')->filter()->all();
            $accountEmail = $booking->user?->hasPlaceholderEmail() ? null : $booking->user?->email;
            $channel = match (true) {
                ! empty($emails) || filled($accountEmail) => 'อีเมล',
                filled($booking->user?->phone) || filled($booking->passengers->first()?->phone) => 'SMS',
                default => '— ไม่มีช่องทาง —',
            };

            return [
                $booking->booking_ref,
                mb_strimwidth((string) $booking->schedule?->trip?->title, 0, 24, '…'),
                $booking->schedule?->departure_date?->toDateString(),
                $channel,
                $booking->brief_sent_at ? ($briefs->digest($booking) === $booking->brief_digest ? 'ส่งแล้ว' : 'มีอัปเดต') : ($briefs->isReady($booking) ? 'พร้อมส่ง' : 'รอข้อมูล'),
            ];
        });

        $this->table(['ใบจอง', 'ทริป', 'วันเดินทาง', 'ช่องทาง', 'สถานะ'], $rows);

        $noChannel = $rows->where(3, '— ไม่มีช่องทาง —')->count();
        if ($noChannel > 0) {
            $this->warn("มี {$noChannel} ใบจองที่ไม่มีทั้งอีเมลและเบอร์โทร — ติดต่อลูกค้าไม่ได้เลย");
        }

        return self::SUCCESS;
    }
}
