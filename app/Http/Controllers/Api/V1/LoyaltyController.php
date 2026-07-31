<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use App\Support\LoyaltyTier;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoyaltyController extends Controller
{
    use ApiResponse;

    public function __construct(private LoyaltyService $loyaltyService) {}

    public function account(Request $request): JsonResponse
    {
        $account = LoyaltyAccount::forUser($request->user()->id);
        $transactions = LoyaltyTransaction::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'points' => $t->points,
                'description' => $t->description,
                'balance_after' => $t->balance_after,
                'expires_at' => $t->expires_at?->toISOString(),
                'created_at' => $t->created_at?->toISOString(),
            ]);

        $tier = LoyaltyTier::find($account->tier);
        $expiring = $this->loyaltyService->expiringSoon($request->user()->id);

        return $this->success([
            'points' => $account->points,
            'lifetime_points' => $account->lifetime_points,
            // จำนวนทริปสะสม — ตัวเลขที่ใช้ตัดสินระดับ (แต้มมีไว้แลกของรางวัลเท่านั้น)
            'lifetime_trips' => (int) $account->lifetime_trips,
            // แต้มมีอายุ 24 เดือน — บอกก้อนที่ใกล้หมดอายุเพื่อให้หน้าจอเตือนได้เอง
            'points_valid_months' => LoyaltyService::POINTS_VALID_MONTHS,
            'expiring_points' => $expiring['points'],
            'expiring_at' => $expiring['at']?->toISOString(),
            'tier' => $account->tier,
            'tier_label' => $tier['label'],
            'tier_tagline' => $tier['tagline'],
            'perks' => $this->perksFor($tier),
            'next_tier' => LoyaltyTier::next($account->tier, (int) $account->lifetime_trips),
            // ส่งบันไดทั้งชุดไปด้วย เพื่อให้เว็บและแอปวาดตารางระดับได้เองโดยไม่ต้อง
            // ฮาร์ดโค้ดชื่อหรือเกณฑ์ซ้ำอีกฝั่ง (ต้นเหตุที่เคยเรียกชื่อไม่ตรงกัน)
            'tiers' => collect(LoyaltyTier::all())->map(fn ($t) => [
                'code' => $t['code'],
                'label' => $t['label'],
                'tagline' => $t['tagline'],
                'min_trips' => $t['min_trips'],
                'perks' => $this->perksFor($t),
            ])->all(),
            'transactions' => $transactions,
        ]);
    }

    public function rewards(Request $request): JsonResponse
    {
        $rewards = LoyaltyReward::where('is_active', true)
            ->get()
            ->map(fn ($r) => $this->formatReward($r));

        return $this->success($rewards);
    }

    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reward_id' => ['required', 'exists:loyalty_rewards,id'],
        ]);

        $reward = LoyaltyReward::findOrFail($validated['reward_id']);

        if (! $reward->is_active) {
            return $this->error('ของรางวัลนี้ไม่พร้อมใช้งานแล้ว', 422);
        }

        if ($reward->stock !== null && $reward->stock <= 0) {
            return $this->error('ของรางวัลหมดแล้ว', 422);
        }

        $account = LoyaltyAccount::forUser($request->user()->id);

        if ($account->points < $reward->points_required) {
            return $this->error('แต้มไม่เพียงพอ (ต้องการ '.$reward->points_required.' แต้ม)', 422);
        }

        try {
            // ตัดสต๊อก แลกแต้ม และออกคูปอง ในธุรกรรมเดียว — ของชิ้นสุดท้ายที่มีคน
            // กดพร้อมกันจะมีคนเดียวที่ได้ อีกคนไม่เสียแต้มฟรี
            $redemption = DB::transaction(function () use ($request, $reward) {
                $locked = LoyaltyReward::whereKey($reward->id)->lockForUpdate()->first();

                if ($locked->stock !== null) {
                    if ($locked->stock <= 0) {
                        throw new \Exception('ของรางวัลหมดแล้ว');
                    }
                    $locked->decrement('stock');
                }

                $redemption = LoyaltyRedemption::create([
                    'user_id' => $request->user()->id,
                    'reward_id' => $locked->id,
                    'source' => LoyaltyRedemption::SOURCE_REWARD,
                    'points_used' => $locked->points_required,
                    'coupon_code' => LoyaltyRedemption::generateCoupon(),
                    'expires_at' => now()->addDays(90),
                ]);

                $this->loyaltyService->spend(
                    $request->user()->id,
                    (int) $locked->points_required,
                    'แลกรับ: '.$locked->name,
                    $redemption,
                );

                return $redemption;
            });
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'coupon_code' => $redemption->coupon_code,
            'reward' => $this->formatReward($reward->fresh()),
            'expires_at' => $redemption->expires_at?->toISOString(),
            'points_remaining' => LoyaltyAccount::forUser($request->user()->id)->points,
        ], 'แลกของรางวัลสำเร็จ', 201);
    }

    public function myCoupons(Request $request): JsonResponse
    {
        $redemptions = LoyaltyRedemption::with('reward')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'coupon_code' => $r->coupon_code,
                'reward_name' => $r->reward?->name,
                'reward_type' => $r->reward?->type,
                'discount_value' => $r->reward?->discount_value,
                'points_used' => $r->points_used,
                'is_used' => $r->is_used,
                'expires_at' => $r->expires_at?->toISOString(),
                'created_at' => $r->created_at?->toISOString(),
            ]);

        return $this->success($redemptions);
    }

    /**
     * สิทธิ์ของระดับในรูปแบบที่หน้าจอเอาไปแสดงได้เลย — ส่งทั้งค่าดิบ (ให้เอาไป
     * คำนวณต่อ) และข้อความไทย (ให้แสดงตรง ๆ โดยไม่ต้องเขียนคำซ้ำทั้งเว็บและแอป)
     *
     * แสดงเฉพาะสิทธิ์ที่ "มีโค้ดบังคับใช้จริงแล้ว" เท่านั้น — อย่าเพิ่มรายการใหม่
     * ที่นี่ก่อนจะมีอะไรไปบังคับใช้ ไม่งั้นจะเป็นการสัญญาสิ่งที่ระบบยังทำไม่ได้
     */
    private function perksFor(array $tier): array
    {
        $perks = [];

        if ($tier['point_multiplier'] > 1) {
            $perks[] = [
                'key' => 'point_multiplier',
                'value' => $tier['point_multiplier'],
                'label' => 'สะสมแต้ม ×'.rtrim(rtrim(number_format($tier['point_multiplier'], 2), '0'), '.'),
            ];
        }

        if ($tier['early_access_hours'] > 0) {
            $perks[] = [
                'key' => 'early_access_hours',
                'value' => $tier['early_access_hours'],
                'label' => 'เปิดจองรอบใหม่ก่อนใคร '.$tier['early_access_hours'].' ชม.',
            ];
        }

        if ($tier['seat_lock_bonus_minutes'] > 0) {
            $perks[] = [
                'key' => 'seat_lock_bonus_minutes',
                'value' => $tier['seat_lock_bonus_minutes'],
                'label' => 'จองที่นั่งได้นานขึ้น '.$tier['seat_lock_bonus_minutes'].' นาที',
            ];
        }

        if ($tier['deposit_discount_percent'] > 0) {
            $perks[] = [
                'key' => 'deposit_discount_percent',
                'value' => $tier['deposit_discount_percent'],
                'label' => 'มัดจำน้อยลง '.$tier['deposit_discount_percent'].'%',
            ];
        }

        if ($tier['birthday_coupon_baht'] > 0) {
            $perks[] = [
                'key' => 'birthday_coupon_baht',
                'value' => $tier['birthday_coupon_baht'],
                'label' => 'ส่วนลดวันเกิด '.number_format($tier['birthday_coupon_baht']).' บาท',
            ];
        }

        if ($tier['code'] !== LoyaltyTier::FRIEND) {
            $perks[] = [
                'key' => 'waitlist_priority',
                'value' => 1,
                'label' => 'ได้คิวรอที่นั่งก่อน',
            ];
        }

        return $perks;
    }

    private function formatReward(LoyaltyReward $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'type' => $r->type,
            // ข้อความอธิบายมูลค่า — ความหมายของ discount_value ต่างกันตามชนิด
            // เขียนที่นี่ที่เดียวเพื่อไม่ให้เว็บกับแอปตีความเองแล้วพูดไม่ตรงกัน
            'value_label' => $this->rewardValueLabel($r),
            'points_required' => $r->points_required,
            'discount_value' => $r->discount_value,
            'is_active' => $r->is_active,
            'stock' => $r->stock,
        ];
    }

    private function rewardValueLabel(LoyaltyReward $r): string
    {
        $value = (float) $r->discount_value;

        return match ($r->type) {
            LoyaltyReward::TYPE_DISCOUNT_PERCENT => 'ลด '.rtrim(rtrim(number_format($value, 2), '0'), '.').'%',
            LoyaltyReward::TYPE_FREE_RENTAL => $value > 0
                ? 'เช่าอุปกรณ์ฟรี มูลค่าไม่เกิน '.number_format($value).' บาท'
                : 'เช่าอุปกรณ์ฟรีทั้งหมดในการจองนี้',
            default => 'ลด '.number_format($value).' บาท',
        };
    }
}
