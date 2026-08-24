<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use App\Support\LoyaltyTier;
use Illuminate\Console\Command;

/**
 * ย้ายแต้มและทริปสะสมของใบจองที่เคยเปลี่ยนมือไปแล้ว ให้ไปอยู่กับเจ้าของคนปัจจุบัน
 *
 * จนถึง 2026-08-24 การโอนใบจองฝั่งแอดมินและการกดรับของขวัญเปลี่ยนแค่
 * `bookings.user_id` เฉย ๆ ไม่ได้แตะเครดิตสะสมเลย (BookingObserver ดูแค่การ
 * เปลี่ยน `status`) แต้มและจำนวนทริปจึงค้างอยู่กับบัญชีที่กดจองครั้งแรก
 * `loyalty:backfill` ซ่อมให้ไม่ได้ เพราะมันข้ามใบจองที่ "เคยได้แต้มไปแล้ว"
 * โดยไม่สนว่าแต้มนั้นเข้าบัญชีใคร
 *
 * ปลอดภัยที่จะรันซ้ำ — รอบถัดไปจะไม่เจอใบจองที่ตรงกันอยู่แล้ว
 */
class SyncLoyaltyBookingOwners extends Command
{
    protected $signature = 'loyalty:sync-owners
                            {--dry-run : แสดงผลลัพธ์โดยไม่บันทึก}
                            {--booking= : จำกัดเฉพาะเลขที่การจองเดียว (ไว้ลองก่อน)}';

    protected $description = 'ย้ายแต้มและทริปสะสมของใบจองที่เปลี่ยนมือแล้ว ไปยังบัญชีเจ้าของปัจจุบัน';

    public function handle(LoyaltyService $loyalty): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ref = $this->option('booking');

        // แถว earn ของใบจองที่ "คนได้แต้ม" กับ "เจ้าของใบจองตอนนี้" ไม่ใช่คนเดียวกัน
        // (ใบจองของแขกที่ไม่มีบัญชีจะไม่เข้าเงื่อนไขนี้ เพราะ user_id เป็น null)
        $rows = LoyaltyTransaction::query()
            ->join('bookings', 'bookings.id', '=', 'loyalty_transactions.reference_id')
            ->where('loyalty_transactions.type', 'earn')
            ->where('loyalty_transactions.reference_type', Booking::class)
            ->whereColumn('bookings.user_id', '!=', 'loyalty_transactions.user_id')
            ->when($ref, fn ($query) => $query->where('bookings.booking_ref', $ref))
            ->orderBy('bookings.id')
            ->get([
                'bookings.id as booking_id',
                'bookings.booking_ref as booking_ref',
                'bookings.status as booking_status',
                'bookings.user_id as owner_id',
                'loyalty_transactions.user_id as credited_user_id',
                'loyalty_transactions.points as points',
                'loyalty_transactions.points_remaining as points_remaining',
            ]);

        if ($rows->isEmpty()) {
            $this->info('ไม่พบใบจองที่แต้มค้างอยู่ผิดบัญชี');

            return self::SUCCESS;
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $spentElsewhere = 0;

        foreach ($rows as $row) {
            $lost = (int) $row->points - (int) $row->points_remaining;
            $spentElsewhere += $lost;

            $this->line(sprintf(
                '  %s (%s): user #%d → user #%d, %d แต้ม%s',
                $row->booking_ref,
                $row->booking_status,
                $row->credited_user_id,
                $row->owner_id,
                (int) $row->points,
                $lost > 0 ? " (เจ้าของเดิมใช้ไปแล้ว {$lost} แต้ม เรียกคืนไม่ได้)" : '',
            ));

            if ($dryRun) {
                continue;
            }

            $booking = Booking::find($row->booking_id);

            if (! $booking) {
                continue;
            }

            $loyalty->transferForBooking($booking);
        }

        $affectedUsers = $rows->pluck('owner_id')
            ->merge($rows->pluck('credited_user_id'))
            ->unique()
            ->values();

        $this->newLine();
        $this->info("{$prefix}ย้ายเครดิต ".$rows->count().' การจอง');

        if ($spentElsewhere > 0) {
            $this->warn(
                "{$prefix}มีแต้ม {$spentElsewhere} แต้มที่เจ้าของเดิมแลกของรางวัลไปแล้ว "
                .'หักคืนไม่ได้ (เจ้าของใหม่ยังได้แต้มเต็มจำนวนตามปกติ)',
            );
        }

        if (! $dryRun) {
            foreach (LoyaltyAccount::whereIn('user_id', $affectedUsers)->get() as $account) {
                $this->line(sprintf(
                    '  user #%d: %s · %d ทริป · %d แต้ม',
                    $account->user_id,
                    LoyaltyTier::label($account->tier),
                    (int) $account->lifetime_trips,
                    (int) $account->points,
                ));
            }
        }

        if ($dryRun) {
            $this->comment('ยังไม่ได้บันทึกอะไร — ตัดตัวเลือก --dry-run ออกเพื่อบันทึกจริง');
        }

        return self::SUCCESS;
    }
}
