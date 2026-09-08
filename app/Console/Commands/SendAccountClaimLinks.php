<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\AccountClaimService;
use Illuminate\Console\Command;

/**
 * ตามเก็บใบจองเก่าที่ค้างอยู่ในบัญชีเงา
 *
 * ตั้งแต่มีระบบนี้ ใบจองที่แอดมินเปิดใหม่จะได้ SMS ลิงก์เปิดใช้บัญชีทันที แต่ใบที่
 * เปิดไปก่อนหน้านั้นยังค้างอยู่เงียบ ๆ — ลูกค้ากลุ่มนี้คือคนที่โหลดแอปมาแล้วเห็น
 * หน้าการจองว่างเปล่าอยู่ทุกวันนี้ คำสั่งนี้ยิงลิงก์ให้ทีเดียว
 *
 * ตั้งใจให้รันมือ ไม่ใส่ตารางเวลา เพราะมันส่ง SMS ที่มีค่าใช้จ่ายจริงเป็นชุด
 * ดู --dry-run ก่อนเสมอ แล้วค่อยปล่อยจริงทีละก้อนด้วย --limit
 */
class SendAccountClaimLinks extends Command
{
    protected $signature = 'bookings:send-claim-links
        {--dry-run : แสดงรายชื่อโดยไม่ส่ง SMS}
        {--limit=50 : ส่งสูงสุดกี่ใบในรอบนี้}';

    protected $description = 'ส่ง SMS ลิงก์เปิดใช้บัญชีให้ลูกค้าที่ทีมงานเปิดใบจองแทนให้ แต่ยังไม่เคยได้ลิงก์';

    public function handle(AccountClaimService $claims): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $bookings = Booking::query()
            ->whereIn('status', AccountClaimService::CLAIMABLE_STATUSES)
            // ยังไม่เคยส่งลิงก์ให้บัญชีนี้เลย — ส่งซ้ำต้องสั่งจากหน้าแอดมินเป็นราย ๆ
            ->whereHas('user', fn ($q) => $q->where('is_shadow', true)->whereNull('claim_token_sent_at'))
            // รอบที่ผ่านไปแล้วไม่ต้องตาม ลูกค้าไม่ได้กำลังรอดูอะไร
            ->whereHas('schedule', fn ($q) => $q->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString()))
            ->with(['user', 'passengers', 'schedule.trip'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('ไม่มีใบจองที่ค้างอยู่ในบัญชีเงา');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($bookings as $booking) {
            $phone = $booking->user?->phone ?: $booking->passengers->first()?->phone;
            $this->line("  {$booking->booking_ref} · {$booking->user?->name} · {$phone}");

            if ($dryRun) {
                continue;
            }

            if ($claims->sendClaimLink($booking)) {
                $sent++;
            }
        }

        $this->info($dryRun
            ? '[dry-run] พบ '.$bookings->count().' ใบที่ควรส่งลิงก์'
            : "ส่งลิงก์เปิดใช้บัญชีแล้ว {$sent} ใบ");

        return self::SUCCESS;
    }
}
