<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GiftVoucher;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Promotion;
use App\Models\SavedPromotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        return response()->json(['data' => $this->presentPublic($this->activePromotions()->get())]);
    }

    /**
     * โปรโมชันที่ยังใช้ได้ตอนนี้ — เปิดอยู่ อยู่ในช่วงวันที่ ยังไม่ใช้ครบ และ
     * Flash Sale ยังไม่หมดเวลา ใช้ร่วมกันทั้งรายการสาธารณะและคูปองที่เก็บไว้
     */
    private function activePromotions(): Builder
    {
        return Promotion::where('promotions.is_active', true)
            ->where(function ($q) {
                $q->whereNull('promotions.start_date')->orWhere('promotions.start_date', '<=', now()->startOfDay());
            })
            ->where(function ($q) {
                $q->whereNull('promotions.end_date')->orWhere('promotions.end_date', '>=', now()->startOfDay());
            })
            ->where(function ($q) {
                $q->whereNull('promotions.max_uses')->orWhereColumn('promotions.used_count', '<', 'promotions.max_uses');
            })
            // A flash sale with a precise deadline drops off the moment it lapses.
            ->where(function ($q) {
                $q->whereNull('promotions.ends_at')->orWhere('promotions.ends_at', '>', now());
            })
            ->select(
                'promotions.id', 'promotions.code', 'promotions.name', 'promotions.type',
                'promotions.value', 'promotions.start_date', 'promotions.end_date',
                'promotions.max_uses', 'promotions.used_count', 'promotions.is_flash_sale',
                'promotions.ends_at', 'promotions.trip_ids'
            )
            // Surface flash sales first so the urgency is front and centre.
            ->orderByDesc('promotions.is_flash_sale')
            ->orderBy('promotions.id', 'desc');
    }

    /**
     * แอปเก็บคูปองไว้ใช้ตอนจอง — ต้องรู้ว่าโค้ดใช้ได้กับทริปไหน จะได้ไม่เสนอ
     * โค้ดที่ validate แล้วตกแน่ ๆ (null = ทุกทริป). ใช้กฎเดียวกับ
     * validateCode/BookingService: จำกัดทริปก็ต่อเมื่อเป็น array ที่ไม่ว่าง
     */
    private function presentPublic(Collection $promotions): Collection
    {
        return $promotions->each(function (Promotion $promotion) {
            $ids = $promotion->trip_ids;
            $ids = is_array($ids)
                ? array_values(array_map('intval', array_filter($ids, 'is_numeric')))
                : [];
            $promotion->setAttribute('trip_ids', $ids === [] ? null : $ids);
        })->values();
    }

    /** เก็บได้ไม่เกินเท่านี้ต่อบัญชี — เก็บใหม่แล้วตัวเก่าสุดหลุด */
    public const SAVED_LIMIT = 50;

    /**
     * คูปองที่ลูกค้าเก็บไว้และยังใช้ได้ ใหม่สุดก่อน — ตัวที่หมดอายุ/ใช้ครบแล้วยัง
     * อยู่ในตาราง แต่ไม่ถูกส่งกลับ (ถ้าแอดมินต่ออายุ มันกลับมาเอง)
     */
    public function saved(Request $request)
    {
        $promotions = $this->activePromotions()
            ->join('saved_promotions', 'saved_promotions.promotion_id', '=', 'promotions.id')
            ->where('saved_promotions.user_id', $request->user()->id)
            ->reorder()
            ->orderByDesc('saved_promotions.updated_at')
            ->orderByDesc('saved_promotions.id')
            ->get();

        return response()->json(['data' => $this->presentPublic($promotions)]);
    }

    /**
     * เก็บคูปอง — `code` ทีละใบ (ตอบแถวที่เก็บ, 404 ถ้าโค้ดใช้ไม่ได้) หรือ
     * `codes` หลายใบพร้อมกัน ตอนแขกล็อกอินแล้วแอปย้ายคูปองในเครื่องเข้าบัญชี
     * (ข้ามโค้ดที่ใช้ไม่ได้แล้วเงียบ ๆ แล้วตอบรายการทั้งหมดของบัญชี)
     *
     * ไม่ใช้ throttle:promotion ร่วมกับ validate — โค้ดที่เก็บได้ล้วนอยู่ใน
     * promotions/active ที่ใครก็เปิดดูได้ การเก็บจึงไม่ใช่ช่องเดาโค้ด และเพดาน
     * 10 ครั้งต่อนาทีนั้นจะตีกลับการย้ายคูปองตอนล็อกอินจนของหาย
     */
    public function save(Request $request)
    {
        $request->validate([
            'code' => 'required_without:codes|string|max:50',
            'codes' => 'array|max:'.self::SAVED_LIMIT,
            'codes.*' => 'string|max:50',
        ]);
        $userId = $request->user()->id;

        if ($request->has('codes')) {
            // ลิสต์ในแอปเรียงใหม่สุดก่อน — บันทึกจากเก่าไปใหม่ ใบแรกในลิสต์จึง
            // ได้เวลา/ไอดีล่าสุดและขึ้นบนสุดเหมือนในเครื่อง
            foreach (array_reverse($request->input('codes')) as $code) {
                $promotion = $this->activePromotions()->where('promotions.code', trim($code))->first();
                if ($promotion) {
                    $this->remember($userId, $promotion);
                }
            }
            $this->trimSaved($userId);

            return $this->saved($request);
        }

        $promotion = $this->activePromotions()
            ->where('promotions.code', trim($request->input('code')))
            ->first();
        if (! $promotion) {
            return response()->json([
                'message' => 'ไม่พบโค้ดนี้ หรือโค้ดหมดอายุแล้ว',
            ], 404);
        }

        $this->remember($userId, $promotion);
        $this->trimSaved($userId);

        return response()->json(['data' => $this->presentPublic(collect([$promotion]))->first()], 201);
    }

    /** เก็บซ้ำ = ดันขึ้นเป็นตัวล่าสุด (เรียงตาม updated_at ลำดับเดียวกับที่แอปแสดง) */
    private function remember(int $userId, Promotion $promotion): void
    {
        $row = SavedPromotion::firstOrCreate(['user_id' => $userId, 'promotion_id' => $promotion->id]);
        if (! $row->wasRecentlyCreated) {
            $row->touch();
        }
    }

    private function trimSaved(int $userId): void
    {
        $overflow = SavedPromotion::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->skip(self::SAVED_LIMIT)
            ->take(PHP_INT_MAX)
            ->pluck('id');
        if ($overflow->isNotEmpty()) {
            SavedPromotion::whereIn('id', $overflow)->delete();
        }
    }

    public function unsave(Request $request, string $code)
    {
        $promotionIds = Promotion::where('code', $code)->pluck('id');
        SavedPromotion::where('user_id', $request->user()->id)
            ->whereIn('promotion_id', $promotionIds)
            ->delete();

        return response()->json(['data' => null]);
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

        // บัตรของขวัญที่ใส่มาในช่องโค้ดส่วนลด (เว็บ/LIFF ไม่มีช่องแยก) — ตอบในรูป
        // fixed เท่ายอดคงเหลือ หน้าจอคิดยอดตัวอย่างได้ถูกโดยไม่ต้องรู้จักบัตร
        // BookingService ย้ายรหัสนี้ไปใช้เป็นบัตรเองตอนจองจริง
        if (GiftVoucher::looksLikeCode($request->code)) {
            $voucher = GiftVoucher::where('code', GiftVoucher::normalizeCode($request->code))->first();
            if ($voucher) {
                return $this->validateVoucher($voucher, $request->user()?->id);
            }
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

    private function validateVoucher(GiftVoucher $voucher, ?int $userId)
    {
        $problem = match (true) {
            $voucher->status === GiftVoucher::STATUS_UNDER_REVIEW => 'บัตรของขวัญนี้กำลังรอยืนยันการชำระเงิน ยังใช้ไม่ได้ครับ',
            $voucher->status !== GiftVoucher::STATUS_ACTIVE => 'ไม่พบบัตรของขวัญนี้ กรุณาตรวจสอบรหัสอีกครั้ง',
            ! $voucher->canBeUsedBy($userId) => 'บัตรของขวัญนี้เป็นของบัญชีอื่น',
            $voucher->isExpired() => 'บัตรของขวัญนี้หมดอายุแล้ว',
            (float) $voucher->balance <= 0 => 'บัตรของขวัญนี้ใช้ครบยอดแล้ว',
            default => null,
        };

        if ($problem) {
            return response()->json(['valid' => false, 'message' => $problem], 400);
        }

        return response()->json([
            'valid' => true,
            'kind' => 'gift_voucher',
            'promotion' => [
                'code' => $voucher->code,
                'name' => 'บัตรของขวัญ',
                'type' => 'fixed',
                'value' => (float) $voucher->balance,
                'is_gift_voucher' => true,
            ],
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
