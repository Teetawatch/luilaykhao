<?php

namespace App\Http\Resources;

use App\Models\CharterRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * คำขอเหมาทริปในมุมของลูกค้า — ไม่มีบันทึกภายในของทีมงาน
 *
 * @mixin CharterRequest
 */
class CharterRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $trip = fn ($t) => $t ? [
            'id' => $t->id,
            'title' => $t->title,
            'slug' => $t->slug,
            'location' => $t->location,
            'cover_image' => $t->cover_image ?? null,
        ] : null;

        $hasQuote = $this->quoted_at !== null && $this->quote_total !== null;

        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'status' => $this->status,
            'trip' => $this->relationLoaded('trip') ? $trip($this->trip) : null,
            'destination' => $this->destination,
            'destination_label' => $this->destinationLabel(),
            'preferred_date' => $this->preferred_date?->toDateString(),
            'alternate_date' => $this->alternate_date?->toDateString(),
            'flexible_dates' => (bool) $this->flexible_dates,
            'duration_days' => $this->duration_days,
            'group_size' => $this->group_size,
            'pickup_area' => $this->pickup_area,
            'budget_per_person' => $this->budget_per_person !== null ? (float) $this->budget_per_person : null,
            'group_type' => $this->group_type,
            'group_type_label' => CharterRequest::GROUP_TYPE_LABELS[$this->group_type] ?? $this->group_type,
            'needs_tax_invoice' => (bool) $this->needs_tax_invoice,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_line' => $this->contact_line,
            'note' => $this->note,
            // ใบเสนอราคาล่าสุด — คงไว้ให้ดูแม้ลูกค้าปฏิเสธ (ทีมงานอาจเสนอใหม่)
            'quote' => $hasQuote ? [
                'trip' => $this->relationLoaded('quoteTrip') ? $trip($this->quoteTrip) : null,
                'departure_date' => $this->quote_departure_date?->toDateString(),
                'return_date' => $this->quote_return_date?->toDateString(),
                'group_size' => $this->quote_group_size,
                'price_per_person' => (float) $this->quote_price_per_person,
                'total' => (float) $this->quote_total,
                'includes' => $this->quote_includes,
                'note' => $this->quote_note,
                'valid_until' => $this->quote_valid_until?->toDateString(),
                'expired' => $this->quoteExpired(),
                'quoted_at' => $this->quoted_at?->toISOString(),
            ] : null,
            'can_accept' => $this->canBeAccepted(),
            'can_decline' => $this->status === CharterRequest::STATUS_QUOTED,
            'can_cancel' => in_array($this->status, [
                CharterRequest::STATUS_NEW, CharterRequest::STATUS_QUOTED, CharterRequest::STATUS_DECLINED,
            ], true),
            'decline_reason' => $this->decline_reason,
            'reject_reason' => $this->status === CharterRequest::STATUS_REJECTED ? $this->reject_reason : null,
            'booking_ref' => $this->relationLoaded('booking') ? $this->booking?->booking_ref : null,
            'booking_status' => $this->relationLoaded('booking') ? $this->booking?->status : null,
            'responded_at' => $this->responded_at?->toISOString(),
            'booked_at' => $this->booked_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
