<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromotionController extends Controller
{
    public function index()
    {
        $promotions = Promotion::orderBy('id', 'desc')->get();

        return response()->json($promotions);
    }

    public function publicActive()
    {
        $promotions = Promotion::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', now()->startOfDay());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now()->startOfDay());
            })
            ->where(function ($q) {
                $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
            })
            // A flash sale with a precise deadline drops off the moment it lapses.
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->select(
                'id', 'code', 'name', 'type', 'value', 'start_date', 'end_date',
                'max_uses', 'used_count', 'is_flash_sale', 'ends_at'
            )
            // Surface flash sales first so the urgency is front and centre.
            ->orderByDesc('is_flash_sale')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $promotions]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:promotions,code',
            'name' => 'required|string',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'trip_ids' => 'nullable|array',
            'trip_ids.*' => 'integer|exists:trips,id',
            'max_uses' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'is_flash_sale' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'ends_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promotion = Promotion::create($validator->validated());

        return response()->json($promotion, 201);
    }

    public function show($id)
    {
        $promotion = Promotion::findOrFail($id);

        return response()->json($promotion);
    }

    public function update(Request $request, $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:promotions,code,'.$promotion->id,
            'name' => 'required|string',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'trip_ids' => 'nullable|array',
            'trip_ids.*' => 'integer|exists:trips,id',
            'max_uses' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'is_flash_sale' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'ends_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promotion->update($validator->validated());

        return response()->json($promotion);
    }

    public function destroy($id)
    {
        $promotion = Promotion::findOrFail($id);

        // Prevent deletion if already used, instead deactivate
        if ($promotion->used_count > 0) {
            $promotion->is_active = false;
            $promotion->save();

            return response()->json(['message' => 'Promotion is already used, so it has been deactivated instead of deleted.']);
        }

        $promotion->delete();

        return response()->json(['message' => 'Promotion deleted successfully']);
    }

    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'trip_id' => 'required|integer|exists:trips,id',
        ]);

        // คูปองส่วนบุคคล (ของขวัญวันเกิด / แลกด้วยแต้ม) ใช้ช่องกรอกโค้ดเดียวกับโปรโมชัน
        // BookingService รับคูปองพวกนี้อยู่แล้ว แต่ปุ่ม "ใช้โค้ด" ยิงมาตรวจที่นี่ก่อน
        // ซึ่งเดิมดูแค่ตาราง promotions — ลูกค้าจึงเจอ "not found" และไปไม่ถึงขั้นจอง
        $redemption = LoyaltyRedemption::with('reward')->where('coupon_code', $request->code)->first();

        if ($redemption) {
            return $this->validateCoupon($redemption, $request->user()?->id);
        }

        $promotion = Promotion::where('code', $request->code)->where('is_active', true)->first();

        if (! $promotion) {
            return response()->json(['valid' => false, 'message' => 'Promotion code not found or inactive'], 404);
        }

        if ($promotion->start_date && now()->startOfDay()->lt($promotion->start_date)) {
            return response()->json(['valid' => false, 'message' => 'Promotion has not started yet'], 400);
        }

        if ($promotion->end_date && now()->startOfDay()->gt($promotion->end_date)) {
            return response()->json(['valid' => false, 'message' => 'Promotion has expired'], 400);
        }

        // Flash sales lapse at a precise time, not just end-of-day.
        if ($promotion->ends_at && now()->gt($promotion->ends_at)) {
            return response()->json(['valid' => false, 'message' => 'Promotion has expired'], 400);
        }

        if ($promotion->max_uses && $promotion->used_count >= $promotion->max_uses) {
            return response()->json(['valid' => false, 'message' => 'Promotion has reached its usage limit'], 400);
        }

        if ($promotion->trip_ids && is_array($promotion->trip_ids)) {
            if (! in_array($request->trip_id, $promotion->trip_ids)) {
                return response()->json(['valid' => false, 'message' => 'Promotion is not applicable for this trip'], 400);
            }
        }

        return response()->json([
            'valid' => true,
            'promotion' => $promotion,
        ]);
    }

    /**
     * ตอบในรูปเดียวกับโปรโมชัน (`type` percent|fixed + `value`) เพราะเว็บ แอป และ
     * LIFF คิดส่วนลดตัวอย่างจากสองช่องนี้ — ยอดจริงคิดใหม่ที่ BookingService เสมอ
     */
    private function validateCoupon(LoyaltyRedemption $redemption, ?int $userId)
    {
        if (! $redemption->isUsableBy($userId)) {
            return response()->json([
                'valid' => false,
                'message' => 'คูปองนี้ใช้ไม่ได้ (อาจถูกใช้ไปแล้ว หมดอายุ หรือไม่ใช่ของบัญชีนี้)',
            ], 400);
        }

        $value = $redemption->rewardValue();

        [$type, $previewValue] = match ($redemption->rewardType()) {
            LoyaltyReward::TYPE_DISCOUNT_PERCENT => ['percent', $value],
            // หักได้เฉพาะค่าเช่าอุปกรณ์ ซึ่งหน้าจองไม่ได้ส่งมา — โชว์ 0 ไว้ก่อนดีกว่า
            // โชว์ส่วนลดเกินจริง แล้วให้ BookingService หักยอดจริงตอนยืนยัน
            LoyaltyReward::TYPE_FREE_RENTAL => ['fixed', 0],
            default => ['fixed', $value],
        };

        return response()->json([
            'valid' => true,
            'promotion' => [
                'code' => $redemption->coupon_code,
                'name' => $redemption->source === LoyaltyRedemption::SOURCE_BIRTHDAY
                    ? 'ของขวัญวันเกิด'
                    : ($redemption->reward?->name ?? 'คูปองสมาชิก'),
                'type' => $type,
                'value' => $previewValue,
                'is_coupon' => true,
                'coupon_type' => $redemption->rewardType(),
                'expires_at' => $redemption->expires_at?->toIso8601String(),
            ],
        ]);
    }
}
