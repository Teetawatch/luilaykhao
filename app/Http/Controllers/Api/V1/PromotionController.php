<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GiftVoucher;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Promotion;
use App\Models\SavedPromotion;
use App\Models\Trip;
use App\Services\PromotionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class PromotionController extends Controller
{
    public function __construct(private PromotionService $promotions) {}

    public function index()
    {
        $promotions = Promotion::orderBy('id', 'desc')->get();

        return response()->json($promotions);
    }

    /**
     * คูปองแจกจำนวนจำกัดที่เก็บครบแล้วไม่โชว์ให้คนทั่วไป — แต่คนที่ถือใบยังเห็น
     * (แอปเอารายการนี้ไปจับคู่กับคูปองที่เก็บไว้ตอนจอง ถ้าหายไป ชิปก็หายด้วย)
     */
    public function publicActive(Request $request)
    {
        $viewer = $request->user('sanctum');
        $promotions = $this->activePromotions()->get();
        $claimed = $viewer
            ? SavedPromotion::where('user_id', $viewer->id)
                ->whereIn('promotion_id', $promotions->pluck('id'))
                ->whereNotNull('claimed_at')
                ->pluck('promotion_id')
                ->flip()
            : collect();

        $visible = $promotions->filter(
            fn (Promotion $p) => ! $p->isClaimLimited() || $p->claimsLeft() > 0 || $claimed->has($p->id)
        );

        return response()->json(['data' => $this->presentPublic($visible, $claimed)]);
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
                'promotions.ends_at', 'promotions.trip_ids',
                'promotions.claim_limit', 'promotions.claimed_count', 'promotions.per_user_limit'
            )
            // Surface flash sales first so the urgency is front and centre.
            ->orderByDesc('promotions.is_flash_sale')
            ->orderBy('promotions.id', 'desc');
    }

    /**
     * แอปเก็บคูปองไว้ใช้ตอนจอง — ต้องรู้ว่าโค้ดใช้ได้กับทริปไหน จะได้ไม่เสนอ
     * โค้ดที่ validate แล้วตกแน่ ๆ (null = ทุกทริป). ใช้กฎเดียวกับ
     * validateCode/BookingService: จำกัดทริปก็ต่อเมื่อเป็น array ที่ไม่ว่าง
     *
     * `trip_types` = หมวดทริป (trips.type = categories.slug) ของทริปที่โค้ดใช้ได้
     * ให้แอปจัดคูปองเป็นหมวดแบบร้านค้าออนไลน์ — null เมื่อใช้ได้ทุกทริป
     *
     * `claims_left` = ใบที่ยังเก็บได้ (null = ไม่จำกัด) / `is_claimed` = คนดูถือใบอยู่
     * ([promotion_id => …] ใน `$claimed`)
     */
    private function presentPublic(Collection $promotions, ?Collection $claimed = null): Collection
    {
        $idsOf = function (Promotion $promotion): array {
            $ids = $promotion->trip_ids;

            return is_array($ids)
                ? array_values(array_map('intval', array_filter($ids, 'is_numeric')))
                : [];
        };

        $typeByTrip = Trip::whereIn('id', $promotions->flatMap($idsOf)->unique()->values())
            ->pluck('type', 'id');

        return $promotions->each(function (Promotion $promotion) use ($idsOf, $typeByTrip, $claimed) {
            $ids = $idsOf($promotion);
            $types = collect($ids)->map(fn ($id) => $typeByTrip[$id] ?? null)
                ->filter()->unique()->values()->all();
            $promotion->setAttribute('trip_ids', $ids === [] ? null : $ids);
            $promotion->setAttribute('trip_types', $ids === [] ? null : $types);
            $promotion->setAttribute('claims_left', $promotion->claimsLeft());
            $promotion->setAttribute('is_claimed', (bool) $claimed?->has($promotion->id));
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
        $userId = $request->user()->id;
        $promotions = $this->activePromotions()
            ->join('saved_promotions', 'saved_promotions.promotion_id', '=', 'promotions.id')
            ->where('saved_promotions.user_id', $userId)
            ->addSelect('saved_promotions.claimed_at as saved_claimed_at')
            ->reorder()
            ->orderByDesc('saved_promotions.updated_at')
            ->orderByDesc('saved_promotions.id')
            ->get();

        // ใช้ครบสิทธิ์ของตัวเองแล้ว / คูปองจำนวนจำกัดที่ไม่ได้ถือใบและเต็มแล้ว —
        // ใช้ไม่ได้แน่ ไม่ส่งกลับ (ใบจองยกเลิกแล้วสิทธิ์คืน มันก็กลับมาเอง)
        $uses = $this->promotions->usesByMany($userId, $promotions->pluck('id'));
        $claimed = $promotions->filter(fn ($p) => $p->saved_claimed_at !== null)->pluck('id')->flip();
        $usable = $promotions->filter(function (Promotion $p) use ($uses, $claimed) {
            if ($p->per_user_limit && ($uses[$p->id] ?? 0) >= $p->per_user_limit) {
                return false;
            }

            return ! $p->isClaimLimited() || $claimed->has($p->id) || $p->claimsLeft() > 0;
        })->each(fn (Promotion $p) => $p->offsetUnset('saved_claimed_at'));

        return response()->json(['data' => $this->presentPublic($usable, $claimed)]);
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
                $promotion = $this->activePromotions()->where('promotions.code', Promotion::normalizeCode($code))->first();
                if ($promotion) {
                    try {
                        $this->promotions->claim($promotion, $userId);
                    } catch (\RuntimeException) {
                        // คูปองจำนวนจำกัดที่แขกเก็บไว้ในเครื่อง แต่ระหว่างนั้นมีคนเก็บครบ
                    }
                }
            }
            $this->trimSaved($userId);

            return $this->saved($request);
        }

        $promotion = $this->activePromotions()
            ->where('promotions.code', Promotion::normalizeCode($request->input('code')))
            ->first();
        if (! $promotion) {
            return response()->json([
                'message' => 'ไม่พบโค้ดนี้ หรือโค้ดหมดอายุแล้ว',
            ], 404);
        }

        // ใช้ครบสิทธิ์ของตัวเองแล้ว เก็บไปก็ใช้ไม่ได้ — บอกตรง ๆ ดีกว่าให้ไปเจอตอนจอง
        if ($promotion->per_user_limit
            && $this->promotions->usesBy($promotion, $userId) >= $promotion->per_user_limit) {
            return response()->json(['message' => __('คุณใช้โค้ดส่วนลดนี้ครบสิทธิ์แล้ว')], 409);
        }

        try {
            // เก็บซ้ำ = ดันขึ้นเป็นตัวล่าสุด (เรียงตาม updated_at ลำดับเดียวกับที่แอปแสดง)
            $this->promotions->claim($promotion, $userId);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        $this->trimSaved($userId);

        $claimed = $promotion->isClaimLimited() ? collect([$promotion->id => true]) : collect();

        return response()->json(['data' => $this->presentPublic(collect([$promotion]), $claimed)->first()], 201);
    }

    /**
     * เกินเพดานแล้วตัวเก่าสุดหลุด — ยกเว้นใบที่ได้จากโควตาคูปองจำนวนจำกัด ซึ่งมี
     * ค่า (ลบทิ้งเงียบ ๆ = ลูกค้าเสียสิทธิ์ที่แย่งมาได้)
     */
    private function trimSaved(int $userId): void
    {
        $overflow = SavedPromotion::where('user_id', $userId)
            ->whereNull('claimed_at')
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
        $promotionIds = Promotion::where('code', Promotion::normalizeCode($code))->pluck('id');
        SavedPromotion::where('user_id', $request->user()->id)
            ->whereIn('promotion_id', $promotionIds)
            ->get()
            // ใบจากโควตาที่ยังไม่ได้ใช้ คืนให้คนอื่นเก็บต่อ
            ->each(fn (SavedPromotion $row) => $this->promotions->release($row));

        return response()->json(['data' => null]);
    }

    /**
     * unique:promotions,code เทียบตรง ๆ — ต้อง normalize ก่อน validate ไม่งั้นบน
     * Postgres "summer10" จะผ่านทั้งที่มี "SUMMER10" อยู่แล้ว แล้วไปชน unique index
     */
    private function withNormalizedCode(Request $request): array
    {
        $input = $request->all();
        if (is_string($input['code'] ?? null)) {
            $input['code'] = Promotion::normalizeCode($input['code']);
        }

        return $input;
    }

    public function store(Request $request)
    {
        $validator = Validator::make($this->withNormalizedCode($request), [
            'code' => 'required|string|unique:promotions,code',
            'name' => 'required|string',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'trip_ids' => 'nullable|array',
            'trip_ids.*' => 'integer|exists:trips,id',
            'max_uses' => 'nullable|integer|min:1',
            'claim_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'is_flash_sale' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'ends_at' => 'nullable|date',
        ], $this->ruleMessages());

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $promotion = Promotion::create($validator->validated());

        return response()->json($promotion, 201);
    }

    /** หน้าแอดมินอ่าน `message` — ส่งข้อแรกเป็นภาษาคนพร้อม errors ทั้งชุด */
    private function validationError(\Illuminate\Contracts\Validation\Validator $validator)
    {
        return response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }

    private function ruleMessages(?Promotion $promotion = null): array
    {
        return [
            'code.unique' => 'รหัสส่วนลดนี้มีอยู่แล้ว',
            'end_date.after_or_equal' => 'วันสิ้นสุดต้องไม่ก่อนวันที่เริ่ม',
            'claim_limit.min' => $promotion && $promotion->claimed_count > 0
                ? "ลูกค้าเก็บคูปองไปแล้ว {$promotion->claimed_count} ใบ ตั้งจำนวนใบต่ำกว่านี้ไม่ได้"
                : 'จำนวนคูปองที่แจกต้องมีอย่างน้อย 1 ใบ',
            'max_uses.min' => $promotion && $promotion->used_count > 0
                ? "โค้ดนี้ถูกใช้ไปแล้ว {$promotion->used_count} ครั้ง ตั้งจำนวนต่ำกว่านี้ไม่ได้"
                : 'จำนวนครั้งที่ใช้ได้ต้องมีอย่างน้อย 1',
            'per_user_limit.min' => 'จำนวนครั้งต่อคนต้องมีอย่างน้อย 1',
        ];
    }

    public function show($id)
    {
        $promotion = Promotion::findOrFail($id);

        return response()->json($promotion);
    }

    public function update(Request $request, $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validator = Validator::make($this->withNormalizedCode($request), [
            'code' => 'required|string|unique:promotions,code,'.$promotion->id,
            'name' => 'required|string',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'trip_ids' => 'nullable|array',
            'trip_ids.*' => 'integer|exists:trips,id',
            'max_uses' => 'nullable|integer|min:'.max(1, (int) $promotion->used_count),
            // ลดจำนวนใบต่ำกว่าที่ลูกค้าเก็บไปแล้วไม่ได้ — ใบที่แจกไปแล้วต้องใช้ได้จริง
            'claim_limit' => 'nullable|integer|min:'.max(1, (int) $promotion->claimed_count),
            'per_user_limit' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'is_flash_sale' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'ends_at' => 'nullable|date',
        ], $this->ruleMessages($promotion));

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $promotion->update($validator->validated());

        return response()->json($promotion);
    }

    public function destroy($id)
    {
        $promotion = Promotion::findOrFail($id);

        // ถูกใช้แล้ว (ใบจองอ้างถึง) หรือมีคนเก็บไว้แล้ว — ปิดแทนลบ เก็บประวัติไว้
        // ลบจริงจะพาคูปองที่ลูกค้าเก็บไว้หายไปด้วยแบบไม่มีร่องรอย
        if ($promotion->used_count > 0 || $promotion->claimed_count > 0 || $promotion->bookings()->exists()) {
            $promotion->is_active = false;
            $promotion->save();

            return response()->json(['message' => 'โปรโมชั่นนี้มีการใช้งานหรือมีลูกค้าเก็บไว้แล้ว จึงปิดใช้งานแทนการลบ', 'deactivated' => true]);
        }

        $promotion->delete();

        return response()->json(['message' => 'ลบโปรโมชั่นแล้ว', 'deactivated' => false]);
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
        $redemption = LoyaltyRedemption::with('reward')->where('coupon_code', Promotion::normalizeCode($request->code))->first();

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

        $promotion = Promotion::where('code', Promotion::normalizeCode($request->code))->first();

        if (! $promotion || ! $promotion->is_active) {
            return response()->json(['valid' => false, 'message' => __('ไม่พบโค้ดส่วนลดนี้ หรือโค้ดถูกปิดใช้งานแล้ว')], 404);
        }

        // กติกาเดียวกับตอนจองจริง (BookingService) — ปุ่มใช้โค้ดผ่าน แต่จองแล้วตก ไม่ได้
        $problem = $this->promotions->problemFor($promotion, $request->user()?->id, (int) $request->trip_id);
        if ($problem !== null) {
            return response()->json(['valid' => false, 'message' => $problem], 400);
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
            // หักได้เฉพาะค่าเช่าอุปกรณ์ ซึ่งที่นี่ไม่รู้ยอด — ส่ง fixed 0 ไว้ให้แอปรุ่นเก่า
            // ส่วนหน้าจองรุ่นใหม่อ่าน coupon_type + rental_cap แล้วคิด min(ค่าเช่า, เพดาน)
            // เอง (กติกาเดียวกับ LoyaltyRedemption::discountFor ที่ BookingService ใช้)
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
                // เพดานค่าเช่าที่คูปองเช่าฟรียกเว้นให้ (บาท) — 0 = ฟรีทั้งหมดไม่จำกัด
                'rental_cap' => $redemption->rewardType() === LoyaltyReward::TYPE_FREE_RENTAL ? $value : null,
                'expires_at' => $redemption->expires_at?->toIso8601String(),
            ],
        ]);
    }
}
