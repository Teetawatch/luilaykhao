<?php

namespace App\Http\Resources;

use App\Models\GiftVoucher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * บัตรของขวัญในมุมของผู้ซื้อหรือเจ้าของบัตร (ผู้เรียกต้องเป็นหนึ่งในสองคนนี้)
 *
 * @mixin GiftVoucher
 */
class GiftVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->id;
        $isPurchaser = $viewerId !== null && $this->purchaser_user_id === $viewerId;
        $isOwner = $viewerId !== null && $this->owner_user_id === $viewerId;
        $active = $this->status === GiftVoucher::STATUS_ACTIVE;
        $awaitingPayment = in_array($this->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_REJECTED], true);

        return [
            'id' => $this->id,
            // รหัสคือตัวเงิน — โชว์เมื่อบัตรใช้ได้แล้วเท่านั้น ก่อนจ่ายไม่มีอะไรให้ส่งต่อ
            'code' => $active ? $this->code : null,
            'display_code' => $active ? $this->displayCode() : null,
            'amount' => (float) $this->amount,
            'balance' => (float) $this->balance,
            // active | used_up | expired | pending | under_review | rejected | cancelled
            'status' => $this->displayStatus(),
            'recipient_name' => $this->recipient_name,
            'from_name' => $this->from_name,
            'message' => $this->message,
            'design' => $this->design,
            'is_complimentary' => (bool) $this->is_complimentary,
            'is_purchaser' => $isPurchaser,
            'is_owner' => $isOwner,
            'for_self' => $this->owner_user_id !== null && $this->owner_user_id === $this->purchaser_user_id,
            // ผู้ซื้อเห็นว่าผู้รับเพิ่มบัตรเข้าบัญชีแล้วหรือยัง (ไม่บอกว่าเป็นใคร)
            'claimed' => $this->owner_user_id !== null,
            'share_url' => $active && $isPurchaser ? $this->shareUrl() : null,
            'review_note' => $this->status === GiftVoucher::STATUS_REJECTED ? $this->review_note : null,
            'can_pay' => $isPurchaser && $awaitingPayment,
            'can_cancel' => $isPurchaser && $awaitingPayment,
            'paid_at' => $this->paid_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'claimed_at' => $this->claimed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
