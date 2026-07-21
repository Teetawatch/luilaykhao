<?php

namespace App\Jobs;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRedemption;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\LoyaltyTier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ของขวัญวันเกิดตามระดับสมาชิก — ออกคูปองส่วนลดให้สมาชิกที่วันเกิดตรงกับวันนี้
 *
 * ออกล่วงหน้าไม่ได้และย้อนหลังไม่ได้ ตั้งใจให้รันวันละครั้งตามเวลาไทย คูปองมีอายุ
 * 30 วันเพื่อให้มีเวลาหาทริปที่ถูกใจ ไม่ใช่ต้องรีบใช้ในวันเกิดพอดี
 */
class IssueBirthdayCouponsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** อายุคูปองนับจากวันที่ออก. */
    public const COUPON_VALID_DAYS = 30;

    public function handle(): void
    {
        $today = now('Asia/Bangkok');

        // ระดับเริ่มต้นไม่มีของขวัญวันเกิด จึงคัดเฉพาะระดับที่มีมูลค่ามากกว่าศูนย์
        $eligibleTiers = collect(LoyaltyTier::all())
            ->filter(fn ($tier) => $tier['birthday_coupon_baht'] > 0)
            ->pluck('code');

        $accounts = LoyaltyAccount::whereIn('tier', $eligibleTiers)->pluck('tier', 'user_id');

        if ($accounts->isEmpty()) {
            return;
        }

        User::whereIn('id', $accounts->keys())
            ->whereNotNull('birth_date')
            ->whereMonth('birth_date', $today->month)
            ->whereDay('birth_date', $today->day)
            ->chunkById(200, function ($users) use ($accounts, $today) {
                foreach ($users as $user) {
                    $this->issueFor($user, $accounts[$user->id], $today->year);
                }
            });
    }

    private function issueFor(User $user, string $tier, int $year): void
    {
        $amount = (float) LoyaltyTier::perk($tier, 'birthday_coupon_baht');

        if ($amount <= 0) {
            return;
        }

        // กันออกซ้ำเมื่อ job ถูกรันหลายรอบในวันเดียวกัน หรือถูก retry
        $alreadyIssued = LoyaltyRedemption::where('user_id', $user->id)
            ->where('source', LoyaltyRedemption::SOURCE_BIRTHDAY)
            ->whereYear('created_at', $year)
            ->exists();

        if ($alreadyIssued) {
            return;
        }

        try {
            $redemption = LoyaltyRedemption::create([
                'user_id' => $user->id,
                'reward_id' => null,
                'source' => LoyaltyRedemption::SOURCE_BIRTHDAY,
                'points_used' => 0,
                'discount_value' => $amount,
                'coupon_code' => LoyaltyRedemption::generateCoupon(),
                'expires_at' => now()->addDays(self::COUPON_VALID_DAYS),
            ]);
        } catch (\Throwable $e) {
            Log::warning('IssueBirthdayCouponsJob: could not issue coupon', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        SmartNotification::create([
            'user_id' => $user->id,
            'type' => 'birthday_coupon',
            'title' => 'สุขสันต์วันเกิด 🎂',
            'body' => 'รับส่วนลด '.number_format($amount).' บาท สำหรับทริปถัดไป'
                .' ใช้โค้ด '.$redemption->coupon_code
                .' ได้ถึง '.$redemption->expires_at->format('d/m/Y'),
            'data' => [
                'coupon_code' => $redemption->coupon_code,
                'discount_value' => $amount,
            ],
        ]);
    }
}
