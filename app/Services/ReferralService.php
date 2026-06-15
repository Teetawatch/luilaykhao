<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralService
{
    /**
     * Return the user's personal referral code, generating and persisting a
     * unique one on first request.
     */
    public function codeFor(User $user): string
    {
        if (! empty($user->referral_code)) {
            return $user->referral_code;
        }

        $code = $this->generateUniqueCode();
        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /**
     * Link a freshly registered user to the referrer who owns $code. Safe to
     * call with a null/blank/invalid code — it simply does nothing then.
     *
     * Guards: programme enabled, code resolves to a real user, not self, and
     * the new user isn't already attributed to someone.
     */
    public function attachReferrer(User $newUser, ?string $code): ?Referral
    {
        if (! config('referral.enabled') || blank($code)) {
            return null;
        }

        $referrer = User::where('referral_code', $this->normalize($code))->first();

        if (! $referrer || $referrer->id === $newUser->id || $newUser->referred_by) {
            return null;
        }

        $newUser->forceFill(['referred_by' => $referrer->id])->save();

        return Referral::firstOrCreate(
            ['referred_user_id' => $newUser->id],
            ['referrer_id' => $referrer->id, 'status' => Referral::STATUS_PENDING],
        );
    }

    /**
     * Called when a booking is paid/confirmed. If this is the referred user's
     * first qualifying booking, award loyalty points to both sides exactly once.
     */
    public function qualifyFromBooking(Booking $booking): void
    {
        if (! config('referral.enabled') || ! $booking->user_id) {
            return;
        }

        DB::transaction(function () use ($booking) {
            $referral = Referral::where('referred_user_id', $booking->user_id)
                ->where('status', Referral::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (! $referral) {
                return; // Not referred, or already rewarded.
            }

            $referrerPoints = (int) config('referral.referrer_points');
            $refereePoints = (int) config('referral.referee_points');

            $this->awardPoints(
                $referral->referrer_id,
                $referrerPoints,
                'โบนัสแนะนำเพื่อน: เพื่อนของคุณจองทริปสำเร็จ',
                $referral,
            );
            $this->awardPoints(
                $referral->referred_user_id,
                $refereePoints,
                'โบนัสต้อนรับจากการแนะนำของเพื่อน',
                $referral,
            );

            $referral->update([
                'status' => Referral::STATUS_REWARDED,
                'qualifying_booking_id' => $booking->id,
                'referrer_points' => $referrerPoints,
                'referee_points' => $refereePoints,
                'rewarded_at' => now(),
            ]);
        });
    }

    /**
     * Snapshot for the referral screen: the user's code, share copy, reward
     * amounts, headline totals and the list of invited friends.
     */
    public function stats(User $user): array
    {
        $code = $this->codeFor($user);

        $referrals = $user->referralsMade()
            ->with('referredUser:id,name,nickname')
            ->latest()
            ->get();

        $rewarded = $referrals->where('status', Referral::STATUS_REWARDED);

        return [
            'enabled' => (bool) config('referral.enabled'),
            'code' => $code,
            'share_url' => $this->shareUrl($code),
            'share_message' => $this->shareMessage($code),
            'referrer_points' => (int) config('referral.referrer_points'),
            'referee_points' => (int) config('referral.referee_points'),
            'summary' => [
                'invited' => $referrals->count(),
                'rewarded' => $rewarded->count(),
                'pending' => $referrals->where('status', Referral::STATUS_PENDING)->count(),
                'points_earned' => (int) $rewarded->sum('referrer_points'),
            ],
            'friends' => $referrals->map(fn (Referral $r) => [
                'name' => $this->maskName($r->referredUser),
                'status' => $r->status,
                'points' => (int) $r->referrer_points,
                'joined_at' => $r->created_at?->toISOString(),
                'rewarded_at' => $r->rewarded_at?->toISOString(),
            ])->values(),
        ];
    }

    private function awardPoints(int $userId, int $points, string $description, Referral $referral): void
    {
        if ($points <= 0) {
            return;
        }

        $account = LoyaltyAccount::forUser($userId);
        $account->points += $points;
        $account->lifetime_points += $points;
        $account->save();
        $account->updateTier();

        LoyaltyTransaction::create([
            'user_id' => $userId,
            'type' => 'earn',
            'points' => $points,
            'description' => $description,
            'reference_type' => Referral::class,
            'reference_id' => $referral->id,
            'balance_after' => $account->points,
        ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            // Ambiguity-free alphabet (no 0/O/1/I) for codes read aloud / typed.
            $code = collect(str_split('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'))
                ->random(8)
                ->implode('');
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    private function normalize(string $code): string
    {
        return Str::upper(trim($code));
    }

    private function shareUrl(string $code): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return "{$base}/invite/{$code}";
    }

    private function shareMessage(string $code): string
    {
        return "มาเที่ยวกับลุยลายเขากันเถอะ! 🏕️ ใช้โค้ดแนะนำเพื่อน \"{$code}\" "
            ."ตอนสมัคร แล้วเราทั้งคู่จะได้รับแต้มสะสมเมื่อคุณจองทริปแรกสำเร็จ\n".$this->shareUrl($code);
    }

    private function maskName(?User $user): string
    {
        $name = trim((string) ($user?->nickname ?: $user?->name));
        if ($name === '') {
            return 'เพื่อนของคุณ';
        }

        $chars = mb_str_split($name);
        if (count($chars) <= 2) {
            return $chars[0].'•';
        }

        return $chars[0].$chars[1].str_repeat('•', min(4, count($chars) - 2));
    }
}
