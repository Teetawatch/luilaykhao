<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
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
        $points = (int) floor((float) $booking->total_amount / $rate);
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($booking, $points) {
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
                'description' => 'สะสมแต้มจากการจอง '.$booking->booking_ref,
                'reference_type' => Booking::class,
                'reference_id' => $booking->id,
                'balance_after' => $account->points,
            ]);
        });
    }
}
