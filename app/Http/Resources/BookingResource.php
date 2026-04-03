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
            'pickup_region' => $this->pickup_region,
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
            'payment_ref' => $this->payment_ref,
            'paid_at' => $this->paid_at?->toISOString(),
            'seats' => BookingSeatResource::collection($this->whenLoaded('seats')),
            'passengers' => BookingPassengerResource::collection($this->whenLoaded('passengers')),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
