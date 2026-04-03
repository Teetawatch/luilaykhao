<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    use ApiResponse;

    public function account(Request $request): JsonResponse
    {
        $account = LoyaltyAccount::forUser($request->user()->id);
        $transactions = LoyaltyTransaction::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($t) => [
                'id'          => $t->id,
                'type'        => $t->type,
                'points'      => $t->points,
                'description' => $t->description,
                'balance_after' => $t->balance_after,
                'created_at'  => $t->created_at?->toISOString(),
            ]);

        return $this->success([
            'points'          => $account->points,
            'lifetime_points' => $account->lifetime_points,
            'tier'            => $account->tier,
            'tier_label'      => $this->tierLabel($account->tier),
            'next_tier'       => $this->nextTier($account->tier, $account->lifetime_points),
            'transactions'    => $transactions,
        ]);
    }

    public function rewards(Request $request): JsonResponse
    {
        $rewards = LoyaltyReward::where('is_active', true)
            ->get()
            ->map(fn($r) => $this->formatReward($r));

        return $this->success($rewards);
    }

    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reward_id' => ['required', 'exists:loyalty_rewards,id'],
        ]);

        $reward = LoyaltyReward::findOrFail($validated['reward_id']);

        if (!$reward->is_active) {
            return $this->error('ของรางวัลนี้ไม่พร้อมใช้งานแล้ว', 422);
        }

        if ($reward->stock !== null && $reward->stock <= 0) {
            return $this->error('ของรางวัลหมดแล้ว', 422);
        }

        $account = LoyaltyAccount::forUser($request->user()->id);

        if ($account->points < $reward->points_required) {
            return $this->error('แต้มไม่เพียงพอ (ต้องการ ' . $reward->points_required . ' แต้ม)', 422);
        }

        $account->points -= $reward->points_required;
        $account->save();

        if ($reward->stock !== null) {
            $reward->decrement('stock');
        }

        $coupon = LoyaltyRedemption::generateCoupon();

        $redemption = LoyaltyRedemption::create([
            'user_id'     => $request->user()->id,
            'reward_id'   => $reward->id,
            'points_used' => $reward->points_required,
            'coupon_code' => $coupon,
            'expires_at'  => now()->addDays(90),
        ]);

        LoyaltyTransaction::create([
            'user_id'       => $request->user()->id,
            'type'          => 'redeem',
            'points'        => -$reward->points_required,
            'description'   => 'แลกรับ: ' . $reward->name,
            'reference_type' => LoyaltyRedemption::class,
            'reference_id'  => $redemption->id,
            'balance_after' => $account->points,
        ]);

        return $this->success([
            'coupon_code' => $coupon,
            'reward'      => $this->formatReward($reward),
            'expires_at'  => $redemption->expires_at?->toISOString(),
            'points_remaining' => $account->points,
        ], 'แลกของรางวัลสำเร็จ', 201);
    }

    public function myCoupons(Request $request): JsonResponse
    {
        $redemptions = LoyaltyRedemption::with('reward')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => [
                'id'          => $r->id,
                'coupon_code' => $r->coupon_code,
                'reward_name' => $r->reward?->name,
                'reward_type' => $r->reward?->type,
                'discount_value' => $r->reward?->discount_value,
                'points_used' => $r->points_used,
                'is_used'     => $r->is_used,
                'expires_at'  => $r->expires_at?->toISOString(),
                'created_at'  => $r->created_at?->toISOString(),
            ]);

        return $this->success($redemptions);
    }

    public static function earnPoints(int $userId, float $amount, int $bookingId): void
    {
        $points = (int) floor($amount / 100); // 1 point per 100 THB
        if ($points <= 0) return;

        $account = LoyaltyAccount::forUser($userId);
        $account->points += $points;
        $account->lifetime_points += $points;
        $account->save();
        $account->updateTier();

        LoyaltyTransaction::create([
            'user_id'       => $userId,
            'type'          => 'earn',
            'points'        => $points,
            'description'   => 'สะสมแต้มจากการจอง #' . $bookingId,
            'reference_type' => Booking::class,
            'reference_id'  => $bookingId,
            'balance_after' => $account->points,
        ]);
    }

    private function tierLabel(string $tier): string
    {
        return match ($tier) {
            'silver' => 'Silver Member',
            'gold'   => 'Gold Member',
            default  => 'Regular Member',
        };
    }

    private function nextTier(string $tier, int $lifetimePoints): ?array
    {
        return match ($tier) {
            'regular' => ['tier' => 'Silver', 'points_needed' => 1500 - $lifetimePoints, 'at' => 1500],
            'silver'  => ['tier' => 'Gold', 'points_needed' => 5000 - $lifetimePoints, 'at' => 5000],
            default   => null,
        };
    }

    private function formatReward(LoyaltyReward $r): array
    {
        return [
            'id'              => $r->id,
            'name'            => $r->name,
            'description'     => $r->description,
            'type'            => $r->type,
            'points_required' => $r->points_required,
            'discount_value'  => $r->discount_value,
            'is_active'       => $r->is_active,
            'stock'           => $r->stock,
        ];
    }
}
