<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Promotion;
use App\Models\SavedPromotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * กติกาของโค้ดโปรโมชัน (ตาราง promotions) ที่เดียว — ทั้งปุ่ม "ใช้โค้ด"
 * (PromotionController::validateCode) และตอนจองจริง (BookingService) ถามที่นี่
 * เดิมสองที่เขียนแยกกันแล้วเพี้ยน (ตอนจองไม่เช็ค ends_at ของ Flash Sale)
 *
 * คูปองแจกจำนวนจำกัด (`claim_limit`): ลูกค้ากด "เก็บ" = ได้ใบหนึ่งจากโควตา
 * (saved_promotions.claimed_at) เก็บครบแล้วคนอื่นเก็บไม่ได้ ส่วนคนที่ถือใบใช้ได้
 * จนหมดอายุแม้โควตาเต็มแล้ว กรอกโค้ดตอนจองโดยยังไม่เคยเก็บ (เว็บ/LIFF ไม่มีกระเป๋า
 * คูปอง) = เก็บให้ตอนนั้นถ้ายังเหลือ
 *
 * จำนวนครั้งต่อคน (`per_user_limit`) นับจากใบจองที่ยังไม่ถูกยกเลิก — ใบที่ยกเลิก/
 * คืนเงินคืนสิทธิ์ให้เอง ส่วน used_count ลดตามใน BookingObserver
 */
class PromotionService
{
    /** ใบจองสถานะนี้ไม่นับว่าใช้โค้ดไปแล้ว — ตรงกับ BookingObserver::REVERSING_STATUSES */
    public const ENDED_STATUSES = ['cancelled', 'refunded'];

    /**
     * เหตุที่ใช้โค้ดนี้ไม่ได้ (ข้อความภาษาไทยผ่าน __()) หรือ null = ใช้ได้
     * `$tripId` null = ไม่ตรวจทริป (เช่น ตอนเก็บคูปองจากหน้าแรก)
     */
    public function problemFor(Promotion $promotion, ?int $userId, ?int $tripId): ?string
    {
        $today = now()->startOfDay();

        return match (true) {
            ! $promotion->is_active => __('ไม่พบโค้ดส่วนลดนี้ หรือโค้ดถูกปิดใช้งานแล้ว'),
            $promotion->start_date && $today->lt($promotion->start_date) => __('โค้ดส่วนลดนี้ยังไม่เริ่มใช้งาน'),
            $promotion->end_date && $today->gt($promotion->end_date),
            $promotion->ends_at && now()->gt($promotion->ends_at) => __('โค้ดส่วนลดนี้หมดอายุแล้ว'),
            $promotion->max_uses && $promotion->used_count >= $promotion->max_uses => __('โค้ดส่วนลดนี้ถูกใช้ครบจำนวนแล้ว'),
            $tripId !== null && ! $promotion->appliesToTrip($tripId) => __('โค้ดส่วนลดนี้ใช้กับทริปนี้ไม่ได้'),
            $userId === null && ($promotion->per_user_limit || $promotion->isClaimLimited()) => __('กรุณาเข้าสู่ระบบก่อนใช้โค้ดส่วนลดนี้'),
            $promotion->per_user_limit && $this->usesBy($promotion, $userId) >= $promotion->per_user_limit => __('คุณใช้โค้ดส่วนลดนี้ครบสิทธิ์แล้ว'),
            $promotion->isClaimLimited() && $promotion->claimsLeft() <= 0 && ! $this->holdsClaim($promotion, $userId) => __('คูปองนี้ถูกเก็บครบจำนวนแล้ว'),
            default => null,
        };
    }

    /** ใบจองของลูกค้าที่ใช้โค้ดนี้และยังไม่ถูกยกเลิก */
    public function usesBy(Promotion $promotion, ?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }

        return Booking::where('user_id', $userId)
            ->where('promotion_id', $promotion->id)
            ->whereNotIn('status', self::ENDED_STATUSES)
            ->count();
    }

    /** เหมือน usesBy แต่ทีละหลายโค้ด — [promotion_id => จำนวนครั้ง] */
    public function usesByMany(int $userId, Collection $promotionIds): Collection
    {
        if ($promotionIds->isEmpty()) {
            return collect();
        }

        return Booking::where('user_id', $userId)
            ->whereIn('promotion_id', $promotionIds)
            ->whereNotIn('status', self::ENDED_STATUSES)
            ->groupBy('promotion_id')
            ->selectRaw('promotion_id, COUNT(*) as uses')
            ->pluck('uses', 'promotion_id')
            ->map(fn ($n) => (int) $n);
    }

    public function holdsClaim(Promotion $promotion, ?int $userId): bool
    {
        return $userId !== null && SavedPromotion::where('user_id', $userId)
            ->where('promotion_id', $promotion->id)
            ->whereNotNull('claimed_at')
            ->exists();
    }

    /**
     * เก็บคูปองเข้าบัญชี — คูปองจำนวนจำกัดได้ใบจากโควตาด้วย (ถ้ายังไม่เคยได้)
     * เก็บซ้ำ = ดันขึ้นเป็นตัวล่าสุด โควตาเต็มแล้วโยน RuntimeException ข้อความไทย
     *
     * ล็อกแถว promotions ก่อนนับ คนกดพร้อมกันจึงไม่แจกเกิน — เรียกจากใน
     * transaction ของ BookingService ได้ (กลายเป็น savepoint)
     */
    public function claim(Promotion $promotion, int $userId): SavedPromotion
    {
        return DB::transaction(function () use ($promotion, $userId) {
            $locked = Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $row = SavedPromotion::firstOrNew(['user_id' => $userId, 'promotion_id' => $locked->id]);

            if ($locked->isClaimLimited() && ! $row->claimed_at) {
                if ($locked->claimsLeft() <= 0) {
                    throw new \RuntimeException(__('คูปองนี้ถูกเก็บครบจำนวนแล้ว'));
                }
                $locked->increment('claimed_count');
                $row->claimed_at = now();
            }

            // ให้ผู้เรียกเห็นยอดล่าสุด (เช่น ตอบ claims_left หลังกดเก็บ)
            $promotion->claimed_count = $locked->claimed_count;

            if ($row->exists && ! $row->isDirty()) {
                $row->touch();
            } else {
                $row->save();
            }

            return $row;
        });
    }

    /**
     * เอาคูปองออกจากบัญชี — ใบที่ยังไม่เคยใช้คืนโควตาให้คนอื่นเก็บต่อ ใบที่ใช้ไป
     * แล้วยังนับว่าแจกไปแล้ว (ไม่งั้นแจกได้เกินจำนวน)
     */
    public function release(SavedPromotion $row): void
    {
        DB::transaction(function () use ($row) {
            $promotion = Promotion::whereKey($row->promotion_id)->lockForUpdate()->first();
            if ($promotion && $row->claimed_at && $this->usesBy($promotion, $row->user_id) === 0) {
                Promotion::whereKey($promotion->id)->where('claimed_count', '>', 0)->decrement('claimed_count');
            }
            $row->delete();
        });
    }

    /** ใบจองที่ใช้โค้ดนี้ถูกยกเลิก/คืนเงิน — คืนจำนวนครั้งให้โค้ด (เรียกครั้งเดียวต่อใบ) */
    public function restoreForBooking(Booking $booking): void
    {
        if (! $booking->promotion_id) {
            return;
        }

        Promotion::whereKey($booking->promotion_id)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
    }
}
