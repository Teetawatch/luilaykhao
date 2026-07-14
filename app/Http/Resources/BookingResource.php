<?php

namespace App\Http\Resources;

use App\Support\MediaDisk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_ref' => $this->booking_ref,
            'user_id' => $this->user_id,
            // ผู้เรียกเป็นเจ้าของการจองหรือไม่ (เพื่อนที่ถูกเชิญจะเป็น false)
            'viewer_is_owner' => $request->user() ? $this->user_id === $request->user()->id : null,
            'user' => $this->when($this->relationLoaded('user'), function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                    'avatar_url' => $this->user->avatar_url,
                ];
            }),
            'schedule' => new TripScheduleResource($this->whenLoaded('schedule')),
            'assigned_staff' => $this->when(
                $this->relationLoaded('schedule') && $this->schedule?->relationLoaded('staff'),
                fn () => $this->schedule->staff->map(fn ($staff) => [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'nickname' => $staff->nickname,
                    'email' => $staff->email,
                    'phone' => $staff->phone,
                    'avatar_url' => $staff->avatar_url,
                ])->values(),
            ),
            'pickup_region' => $this->pickup_region,
            'pickup_point' => $this->when($this->relationLoaded('pickupPoint') && $this->pickupPoint, function () {
                return [
                    'id' => $this->pickupPoint->id,
                    'region' => $this->pickupPoint->region,
                    'pickup_location' => $this->pickupPoint->pickup_location,
                    'region_label' => $this->pickupPoint->region_label,
                    'map_url' => $this->pickupPoint->map_url,
                    'image_url' => $this->pickupPoint->image_url,
                    'notes' => $this->pickupPoint->notes,
                ];
            }),
            'custom_pickup' => $this->when($this->custom_pickup_status !== null, fn () => [
                'label' => $this->custom_pickup_label,
                'lat' => $this->custom_pickup_lat,
                'lng' => $this->custom_pickup_lng,
                'note' => $this->custom_pickup_note,
                'status' => $this->custom_pickup_status,
                'price' => $this->custom_pickup_price,
                'reject_reason' => $this->custom_pickup_reject_reason,
                'resolved_at' => $this->custom_pickup_resolved_at?->toISOString(),
            ]),
            'is_group' => $this->is_group,
            'group_name' => $this->group_name,
            'group_notes' => $this->group_notes,
            'is_join_trip' => (bool) $this->is_join_trip,
            'qr_code' => $this->qr_code,
            'checked_in' => $this->checked_in,
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            'status' => $this->status,
            'can_review' => $this->status === 'confirmed'
                && $this->relationLoaded('schedule')
                && $this->schedule
                && $this->schedule->isReviewAvailable()
                && ! ($this->relationLoaded('review') && $this->review),
            'has_reviewed' => $this->relationLoaded('review') ? (bool) $this->review : null,
            'can_modify' => $this->relationLoaded('schedule') && $this->schedule
                ? $this->canBeModified()
                : false,
            'modification_deadline' => $this->relationLoaded('schedule') && $this->schedule
                ? $this->modificationDeadline()?->toISOString()
                : null,
            'can_reschedule' => $this->relationLoaded('schedule') && $this->schedule
                ? $this->canBeRescheduled()
                : false,
            'reschedule_deadline' => $this->relationLoaded('schedule') && $this->schedule
                ? $this->rescheduleDeadline()?->toISOString()
                : null,
            'rescheduled_at' => $this->rescheduled_at?->toISOString(),
            'total_amount' => $this->total_amount,
            // ส่วนต่าง Flexi-Price ที่ตกลงจ่ายเพิ่ม (เก็บวันเดินทาง) — null เมื่อไม่เข้าร่วม
            'flexi_surcharge' => $this->flexi_surcharge,
            'selected_addons' => $this->selected_addons ?? [],
            'addons_total' => $this->addons_total,
            'selected_rentals' => $this->selected_rentals ?? [],
            'rentals_total' => $this->rentals_total,
            'paid_amount' => $this->paid_amount,
            'payment_method' => $this->payment_method,
            'payment_type' => $this->payment_type ?? 'full',
            'installment_count' => $this->installment_count,
            'installment_interval_days' => $this->installment_interval_days,
            'deposit_amount' => $this->deposit_amount,
            'balance_amount' => $this->balance_amount,
            'balance_due_at' => $this->balance_due_at?->toISOString(),
            'balance_paid_at' => $this->balance_paid_at?->toISOString(),
            'balance_payment_ref' => $this->balance_payment_ref,
            'balance_slip_url' => MediaDisk::slipUrl($this->balance_slip_path),
            'balance_transfer_datetime' => $this->balance_transfer_datetime?->toISOString(),
            'payment_ref' => $this->payment_ref,
            'slip_url' => MediaDisk::slipUrl($this->slip_path),
            'slip_ocr_status' => $this->slip_ocr_status,
            'transfer_datetime' => $this->transfer_datetime?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'balance_slip_ocr_status' => $this->balance_slip_ocr_status,
            'installment_payments' => $this->when(
                $this->relationLoaded('installmentPayments'),
                fn () => $this->installmentPayments->map(fn ($ip) => [
                    'id' => $ip->id,
                    'installment_no' => $ip->installment_no,
                    'amount' => $ip->amount,
                    'due_date' => $ip->due_date?->toDateString(),
                    'status' => $ip->status,
                    'payment_method' => $ip->payment_method,
                    'slip_url' => MediaDisk::slipUrl($ip->slip_path),
                    'slip_ocr_status' => $ip->slip_ocr_status,
                    'transfer_datetime' => $ip->transfer_datetime?->toISOString(),
                    'paid_at' => $ip->paid_at?->toISOString(),
                ])
            ),
            // สรุปการแบ่งจ่ายกลุ่ม — มีเมื่อ controller โหลด splitShares มาด้วย
            'split' => $this->when(
                $this->relationLoaded('splitShares'),
                fn () => [
                    'enabled' => $this->splitShares->isNotEmpty(),
                    'total_shares' => $this->splitShares->count(),
                    'paid_shares' => $this->splitShares->where('status', 'paid')->count(),
                ]
            ),
            'seats' => BookingSeatResource::collection($this->whenLoaded('seats')),
            'passengers' => BookingPassengerResource::collection($this->whenLoaded('passengers')),
            'staff_reviews' => $this->when(
                $this->relationLoaded('staffReviews'),
                fn () => $this->staffReviews->map(fn ($review) => [
                    'id' => $review->id,
                    'staff_user_id' => $review->staff_user_id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at?->toISOString(),
                ])->values(),
            ),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'refund_status' => $this->refund_status,
            'refund_amount' => $this->refund_amount,
            'refunded_at' => $this->refunded_at?->toISOString(),
            'refund_slip_url' => MediaDisk::slipUrl($this->refund_slip_path),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
