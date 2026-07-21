<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Support\LoyaltyTier;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    /**
     * Award loyalty points for a confirmed booking: floor(total_amount / rate),
     * where the rate defaults to 100 THB = 1 point. Runs once per booking
     * (idempotent) and only for account holders.
     */
    public function awardForBooking(Booking $booking): void
    {
        if (! $booking->user_id) {
            return; // Guest booking — no account to credit.
        }

        $rate = max(1, (int) config('loyalty.baht_per_point', 100));
        $basePoints = (int) floor((float) $booking->total_amount / $rate);
        if ($basePoints <= 0) {
            return;
        }

        // ตัวคูณคิดจากระดับ ณ ตอนที่ได้แต้ม — คนที่เพิ่งขึ้นระดับจะได้ตัวคูณใหม่
        // ตั้งแต่ทริปถัดไป ไม่ย้อนหลังให้ทริปที่จ่ายไปแล้ว
        $multiplier = (float) LoyaltyTier::perk(
            LoyaltyAccount::tierForUser($booking->user_id),
            'point_multiplier',
        );

        $points = max($basePoints, (int) floor($basePoints * $multiplier));
        $bonus = $points - $basePoints;

        DB::transaction(function () use ($booking, $points, $bonus) {
            $alreadyEarned = LoyaltyTransaction::where('reference_type', Booking::class)
                ->where('reference_id', $booking->id)
                ->where('type', 'earn')
                ->lockForUpdate()
                ->exists();

            if ($alreadyEarned) {
                return; // Points for this booking were already granted.
            }

            $account = LoyaltyAccount::forUser($booking->user_id);
            $account->points += $points;
            $account->lifetime_points += $points;
            $account->save();
            $account->updateTier();

            LoyaltyTransaction::create([
                'user_id' => $booking->user_id,
                'type' => 'earn',
                'points' => $points,
                'description' => 'สะสมแต้มจากการจอง '.$booking->booking_ref
                    .($bonus > 0 ? ' (รวมโบนัสระดับสมาชิก +'.$bonus.')' : ''),
                'reference_type' => Booking::class,
                'reference_id' => $booking->id,
                'balance_after' => $account->points,
            ]);
        });
    }
}
