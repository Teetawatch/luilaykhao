<?php

namespace App\Jobs;

use App\Models\LoyaltyTransaction;
use App\Models\SmartNotification;
use App\Services\LoyaltyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * เตือนล่วงหน้า 30 วันก่อนแต้มก้อนหนึ่งจะหมดอายุ
 *
 * แต้มหายไปเงียบ ๆ คือวิธีที่ดีที่สุดในการทำให้ลูกค้าเลิกเชื่อระบบสะสมแต้ม —
 * เตือนครั้งเดียวต่อวันที่หมดอายุหนึ่งวัน (กันซ้ำด้วย type + ข้อมูลใน payload)
 * และเตือนเฉพาะคนที่มีแต้มมากพอจะแลกอะไรได้จริง
 */
class WarnExpiringLoyaltyPointsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ต่ำกว่านี้ไม่ต้องเตือน — แลกอะไรไม่ได้อยู่ดี รบกวนเปล่า ๆ. */
    public const MIN_POINTS_WORTH_WARNING = 10;

    public function handle(): void
    {
        $target = now('Asia/Bangkok')->addDays(LoyaltyService::EXPIRY_WARNING_DAYS);

        LoyaltyTransaction::where('type', 'earn')
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', $target->toDateString())
            ->get()
            ->groupBy('user_id')
            ->each(function ($lots, $userId) use ($target) {
                $points = (int) $lots->sum('points_remaining');

                if ($points < self::MIN_POINTS_WORTH_WARNING) {
                    return;
                }

                $alreadyWarned = SmartNotification::where('user_id', $userId)
                    ->where('type', 'loyalty_points_expiring')
                    ->whereDate('created_at', now('Asia/Bangkok')->toDateString())
                    ->exists();

                if ($alreadyWarned) {
                    return;
                }

                SmartNotification::send(
                    (int) $userId,
                    'loyalty_points_expiring',
                    'แต้มของคุณกำลังจะหมดอายุ',
                    'แต้ม '.number_format($points).' แต้มจะหมดอายุวันที่ '
                        .$target->format('d/m/Y').' — แลกของรางวัลก่อนได้เลย',
                    [
                        'points' => $points,
                        'expires_at' => $target->toDateString(),
                        'route' => 'rewards',
                    ],
                );
            });
    }
}
