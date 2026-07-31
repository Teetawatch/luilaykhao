<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use App\Support\LoyaltyTier;
use Illuminate\Console\Command;

/**
 * ไล่นับแต้มและจำนวนทริปย้อนหลังให้ครบทุกการจองที่ยืนยันแล้ว
 *
 * ระบบสะสมแต้มเพิ่งเริ่มใช้กลางเดือนมิถุนายน 2026 และก่อนหน้านั้นการจองที่แอดมิน
 * ยืนยันเองไม่เคยเข้าบัญชีสมาชิกเลย ลูกค้าที่เที่ยวกับเรามาหลายรอบจึงยังเป็น
 * "เพื่อนร่วมทาง" อยู่ ป้ายระดับ (ฉายา) จึงไม่เคยขึ้นให้ใครเห็น
 *
 * ปลอดภัยที่จะรันซ้ำ — LoyaltyService กันการให้ซ้ำด้วยแถว earn ของแต่ละการจอง
 */
class BackfillLoyalty extends Command
{
    protected $signature = 'loyalty:backfill
                            {--dry-run : แสดงผลลัพธ์โดยไม่บันทึก}
                            {--user= : จำกัดเฉพาะ user id เดียว (ไว้ลองก่อน)}';

    protected $description = 'นับแต้มและจำนวนทริปย้อนหลังจากการจองที่ยืนยันแล้ว แล้วคำนวณระดับสมาชิกใหม่';

    /** สถานะที่ถือว่าได้เดินทางกับเราแล้ว — ตรงกับ BookingObserver. */
    private const EARNING_STATUSES = ['confirmed', 'completed'];

    public function handle(LoyaltyService $loyalty): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $credited = 0;
        $skipped = 0;
        /** @var array<int, int> ทริปที่จะถูกนับเพิ่ม แยกตามผู้ใช้ — ใช้ตอน dry-run เท่านั้น */
        $pendingTrips = [];

        // เรียงตามลำดับเวลา เพื่อให้ตัวคูณแต้มของแต่ละระดับถูกใช้ตามลำดับที่
        // ลูกค้าไต่ระดับขึ้นไปจริง ๆ เหมือนกับตอนที่ระบบทำงานสด
        Booking::query()
            ->whereIn('status', self::EARNING_STATUSES)
            ->whereNotNull('user_id')
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->chunk(200, function ($bookings) use ($loyalty, $dryRun, &$credited, &$skipped, &$pendingTrips) {
                foreach ($bookings as $booking) {
                    $already = LoyaltyTransaction::where('reference_type', Booking::class)
                        ->where('reference_id', $booking->id)
                        ->where('type', 'earn')
                        ->exists();

                    if ($already) {
                        $skipped++;

                        continue;
                    }

                    $credited++;

                    if ($dryRun) {
                        $pendingTrips[$booking->user_id] = ($pendingTrips[$booking->user_id] ?? 0) + 1;
                        $this->line("  {$booking->booking_ref}: ยังไม่เคยได้แต้ม (ยอด ".number_format((float) $booking->total_amount).' บาท)');

                        continue;
                    }

                    $loyalty->awardForBooking($booking);
                }
            });

        // คำนวณระดับใหม่ให้ทุกบัญชี รวมถึงบัญชีที่ค้างระดับเก่า (regular/silver/gold)
        // หรือบัญชีที่ยังไม่เคยมีการจองเลย ให้ตกไปอยู่ระดับเริ่มต้นอย่างถูกต้อง
        $promoted = [];

        LoyaltyAccount::query()
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->chunkById(200, function ($accounts) use ($dryRun, $pendingTrips, &$promoted) {
                foreach ($accounts as $account) {
                    // ตอน dry-run ยังไม่มีการบันทึกทริป จึงต้องบวกทริปที่กำลังจะถูก
                    // นับเข้าไปเอง ไม่งั้นรายงานจะบอกว่าไม่มีใครได้เลื่อนระดับ
                    $trips = (int) $account->lifetime_trips + ($pendingTrips[$account->user_id] ?? 0);
                    $shouldBe = LoyaltyTier::forTrips($trips);

                    if ($shouldBe === $account->tier) {
                        continue;
                    }

                    $promoted[] = sprintf(
                        '  user #%d: %s → %s (%d ทริป)',
                        $account->user_id,
                        LoyaltyTier::label($account->tier),
                        LoyaltyTier::label($shouldBe),
                        $trips,
                    );

                    if (! $dryRun) {
                        $account->updateTier();
                    }
                }
            });

        // ลูกค้าที่ยังไม่มีแถวในตารางสมาชิกเลย (ไม่เคยได้แต้มสักครั้ง) จะถูกสร้าง
        // บัญชีให้ตอนให้แต้มจริง — ตอน dry-run ต้องประเมินให้เองว่าเขาจะได้ระดับอะไร
        if ($dryRun && $pendingTrips !== []) {
            $withoutAccount = array_diff(
                array_keys($pendingTrips),
                LoyaltyAccount::whereIn('user_id', array_keys($pendingTrips))->pluck('user_id')->all(),
            );

            foreach ($withoutAccount as $newUserId) {
                $trips = $pendingTrips[$newUserId];
                $shouldBe = LoyaltyTier::forTrips($trips);

                if ($shouldBe !== LoyaltyTier::FRIEND) {
                    $promoted[] = sprintf(
                        '  user #%d: (ยังไม่มีบัญชีสมาชิก) → %s (%d ทริป)',
                        $newUserId,
                        LoyaltyTier::label($shouldBe),
                        $trips,
                    );
                }
            }
        }

        $reconciled = $this->reconcilePointLots($dryRun, $userId);

        $prefix = $dryRun ? '[dry-run] ' : '';

        foreach ($promoted as $line) {
            $this->line($line);
        }

        $this->info("{$prefix}ให้แต้มย้อนหลัง {$credited} การจอง (ข้ามที่เคยได้แล้ว {$skipped} การจอง)");
        $this->info("{$prefix}เปลี่ยนระดับสมาชิก ".count($promoted).' บัญชี');
        $this->info("{$prefix}ตั้งวันหมดอายุให้แต้มก้อนเก่า {$reconciled} ก้อน");

        if ($dryRun) {
            $this->comment('ยังไม่ได้บันทึกอะไร — ตัดตัวเลือก --dry-run ออกเพื่อบันทึกจริง');
        }

        return self::SUCCESS;
    }

    /**
     * เกลี่ยแต้มคงเหลือของแต่ละคนลงล็อต แล้วตั้งวันหมดอายุให้แต้มยุคก่อนมีระบบล็อต
     *
     * แต้มที่ลูกค้าแลกไปแล้วในอดีตไม่มีร่องรอยว่าตัดจากก้อนไหน จึงเกลี่ยจากก้อนใหม่
     * สุดย้อนลงไปจนครบยอดคงเหลือ (ก้อนเก่าที่เหลือถือว่าถูกใช้ไปแล้ว) วิธีนี้เข้าข้าง
     * ลูกค้าเพราะแต้มที่ยังอยู่จะเป็นก้อนใหม่ที่มีอายุยาวกว่า
     *
     * วันหมดอายุมีระยะผ่อนผันอย่างน้อย 90 วันนับจากวันนี้ — ไม่มีใครตื่นมาแล้วพบว่า
     * แต้มหายไปเพราะเราเพิ่งประกาศกติกาเมื่อคืน
     */
    private function reconcilePointLots(bool $dryRun, ?int $userId): int
    {
        $touched = 0;
        $floor = now()->addDays(90);

        LoyaltyAccount::query()
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->chunkById(200, function ($accounts) use ($dryRun, $floor, &$touched) {
                foreach ($accounts as $account) {
                    $lots = LoyaltyTransaction::where('user_id', $account->user_id)
                        ->where('type', 'earn')
                        ->whereNull('expires_at')
                        ->orderByDesc('id')
                        ->get();

                    if ($lots->isEmpty()) {
                        continue;
                    }

                    $budget = (int) $account->points;

                    foreach ($lots as $lot) {
                        $remaining = min($budget, max(0, (int) $lot->points));
                        $budget -= $remaining;
                        $touched++;

                        if ($dryRun) {
                            continue;
                        }

                        $expiresAt = $lot->created_at
                            ? $lot->created_at->copy()->addMonths(LoyaltyService::POINTS_VALID_MONTHS)
                            : $floor;

                        $lot->update([
                            'points_remaining' => $remaining,
                            'expires_at' => $expiresAt->lt($floor) ? $floor : $expiresAt,
                        ]);
                    }
                }
            });

        return $touched;
    }
}
