<?php

namespace App\Http\Resources;

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
            'user' => $this->when($this->relationLoaded('user'), function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ];
            }),
            'schedule' => new TripScheduleResource($this->whenLoaded('schedule')),
            'assigned_staff' => $this->when(
                $this->relationLoaded('schedule') && $this->schedule?->relationLoaded('staff'),
                fn () => $this->schedule->staff->map(fn ($staff) => [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'email' => $staff->email,
                    'phone' => $staff->phone,
                    'avatar_url' => $staff->avatar_url,
                ])->values(),
            ),
            'pickup_region' => $this->pickup_region,
            'pickup_point' => $this->when($this->relationLoaded('pickupPoint') && $this->pickupPoint, function() {
                return [
                    'id' => $this->pickupPoint->id,
                    'region' => $this->pickupPoint->region,
                    'pickup_location' => $this->pickupPoint->pickup_location,
                    'region_label' => $this->pickupPoint->region_label,
                    'map_url' => $this->pickupPoint->map_url,
                    'notes' => $this->pickupPoint->notes,
                ];
            }),
            'is_group' => $this->is_group,
            'group_name' => $this->group_name,
            'group_notes' => $this->group_notes,
            'qr_code' => $this->qr_code,
            'checked_in' => $this->checked_in,
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'payment_method' => $this->payment_method,
            'payment_type' => $this->payment_type ?? 'full',
            'installment_count' => $this->installment_count,
            'installment_interval_days' => $this->installment_interval_days,
            'payment_ref' => $this->payment_ref,
            'slip_url' => $this->slip_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->slip_path) : null,
            'transfer_datetime' => $this->transfer_datetime?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'installment_payments' => $this->when(
                $this->relationLoaded('installmentPayments'),
                fn () => $this->installmentPayments->map(fn ($ip) => [
                    'id'              => $ip->id,
                    'installment_no'  => $ip->installment_no,
                    'amount'          => $ip->amount,
                    'due_date'        => $ip->due_date?->toDateString(),
                    'status'          => $ip->status,
                    'payment_method'  => $ip->payment_method,
                    'slip_url'        => $ip->slip_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($ip->slip_path) : null,
                    'transfer_datetime' => $ip->transfer_datetime?->toISOString(),
                    'paid_at'         => $ip->paid_at?->toISOString(),
                ])
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
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
