<?php

namespace App\Services;

use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\StayExperience;
use App\Models\StayReview;
use App\Models\StayRoomType;
use App\Support\MediaDisk;

/**
 * รูปร่าง JSON ของที่พัก/ใบจอง — ลูกค้า เจ้าของ และหลังบ้านเห็นคนละชุด
 *
 * ลูกค้าไม่เห็นเบอร์เจ้าของจนกว่าใบจองจะยืนยัน · เจ้าของเห็นชื่อ เบอร์ จำนวนคน
 * และหมายเหตุของแขกเท่าที่ต้องใช้ต้อนรับ ไม่เห็นอีเมล/ยอดค่าคอม/บัญชีคืนเงิน
 */
class StayPresenter
{
    public static function card(Stay $stay, ?string $distanceNote = null): array
    {
        $rooms = $stay->relationLoaded('roomTypes') ? $stay->roomTypes->where('is_active', true) : collect();

        return [
            'id' => $stay->id,
            'slug' => $stay->slug,
            'name' => $stay->name,
            'type' => $stay->type,
            'type_label' => $stay->typeLabel(),
            'community_name' => $stay->community_name,
            'province' => $stay->province,
            'district' => $stay->district,
            'summary' => $stay->summary,
            'cover_image' => $stay->coverUrl(),
            'booking_mode' => $stay->booking_mode,
            'price_from' => $rooms->isNotEmpty() ? (float) $rooms->min('price_per_night') : null,
            'community_share_percent' => $stay->communitySharePercent(),
            'rating_avg' => $stay->rating_avg !== null ? (float) $stay->rating_avg : null,
            'rating_count' => (int) $stay->rating_count,
            'amenities' => self::amenities($stay->amenities),
            'distance_note' => $distanceNote,
        ];
    }

    public static function detail(Stay $stay): array
    {
        $reviews = $stay->reviews()->where('is_hidden', false)->with('user:id,name,nickname,avatar')->latest()->limit(20)->get();

        return self::card($stay) + [
            'description' => $stay->description,
            'community_story' => $stay->community_story,
            'highlights' => array_values($stay->highlights ?? []),
            'house_rules' => $stay->house_rules,
            'address' => $stay->address,
            'latitude' => $stay->latitude,
            'longitude' => $stay->longitude,
            'map_url' => $stay->map_url,
            'check_in_time' => $stay->check_in_time,
            'check_out_time' => $stay->check_out_time,
            'gallery' => $stay->galleryUrls(),
            'license_type' => $stay->license_type,
            'license_label' => $stay->license_type ? (Stay::LICENSE_TYPES[$stay->license_type] ?? null) : null,
            'license_no' => $stay->license_no,
            'cancellation_policy' => self::policy($stay->cancellation_policy),
            'room_types' => $stay->roomTypes->where('is_active', true)->map(fn (StayRoomType $r) => self::room($r))->values()->all(),
            'experiences' => $stay->experiences->where('is_active', true)->map(fn (StayExperience $e) => self::experience($e))->values()->all(),
            'trips' => $stay->trips->where('status', 'active')->map(fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'title' => $t->title,
                'cover_image' => MediaDisk::url($t->cover_image),
                'distance_note' => $t->pivot?->distance_note,
            ])->values()->all(),
            'reviews' => $reviews->map(fn (StayReview $r) => self::review($r))->all(),
            'payment_minutes' => (int) config('stays.instant_payment_minutes', 30),
        ];
    }

    public static function room(StayRoomType $room): array
    {
        return [
            'id' => $room->id,
            'name' => $room->name,
            'description' => $room->description,
            'units' => (int) $room->units,
            'base_guests' => (int) $room->base_guests,
            'max_guests' => (int) $room->max_guests,
            'price_per_night' => (float) $room->price_per_night,
            'weekend_price' => $room->weekend_price !== null ? (float) $room->weekend_price : null,
            'extra_guest_price' => (float) $room->extra_guest_price,
            'breakfast_included' => (bool) $room->breakfast_included,
            'photos' => $room->photoUrls(),
        ];
    }

    public static function experience(StayExperience $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'price_per_person' => (float) $e->price_per_person,
            'duration_label' => $e->duration_label,
            'image' => $e->imageUrl(),
        ];
    }

    public static function review(StayReview $r): array
    {
        $name = trim((string) ($r->user?->nickname ?: $r->user?->name ?: 'ผู้เข้าพัก'));

        return [
            'id' => $r->id,
            'rating' => (int) $r->rating,
            'comment' => $r->comment,
            'host_reply' => $r->host_reply,
            // ชื่อเต็มของลูกค้าไม่ออกสู่หน้าสาธารณะ
            'author' => mb_substr($name, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($name) - 1))),
            'created_at' => $r->created_at?->toISOString(),
        ];
    }

    public static function policy(string $key): array
    {
        $policy = (array) config('stays.policies.'.$key, []);
        $tiers = collect($policy['tiers'] ?? [])->sortByDesc('days')->values();

        $lines = $tiers->map(fn ($t) => "ยกเลิกก่อนเข้าพัก {$t['days']} วันขึ้นไป คืน {$t['percent']}%")->all();
        $last = $tiers->last();
        if ($last) {
            $lines[] = "น้อยกว่า {$last['days']} วัน ไม่คืนเงิน";
        }

        return [
            'key' => $key,
            'label' => $policy['label'] ?? $key,
            'lines' => $lines,
        ];
    }

    /** ใบจองในมุมของลูกค้า */
    public static function booking(StayBooking $b): array
    {
        $stay = $b->stay;
        $confirmed = in_array($b->status, [StayBooking::STATUS_CONFIRMED, StayBooking::STATUS_COMPLETED], true);

        return self::bookingBase($b) + [
            'stay' => $stay ? [
                'id' => $stay->id,
                'slug' => $stay->slug,
                'name' => $stay->name,
                'cover_image' => $stay->coverUrl(),
                'province' => $stay->province,
                'community_name' => $stay->community_name,
                'check_in_time' => $stay->check_in_time,
                'check_out_time' => $stay->check_out_time,
                'community_share_percent' => round(100 - (float) $b->commission_percent, 2),
                // ที่อยู่และเบอร์เจ้าของ — เปิดหลังยืนยันการจองแล้ว
                'address' => $confirmed ? $stay->address : null,
                'map_url' => $confirmed ? $stay->map_url : null,
                'latitude' => $confirmed ? $stay->latitude : null,
                'longitude' => $confirmed ? $stay->longitude : null,
                'host_name' => $confirmed ? $stay->host_name : null,
                'host_phone' => $confirmed ? $stay->host_phone : null,
                'host_line_id' => $confirmed ? $stay->host_line_id : null,
            ] : null,
            'guest_email' => $b->guest_email,
            'nightly_prices' => $b->nightly_prices ?? [],
            'room_amount' => (float) $b->room_amount,
            'extra_guest_amount' => (float) $b->extra_guest_amount,
            'experiences' => $b->experiences ?? [],
            'experiences_amount' => (float) $b->experiences_amount,
            'host_amount' => (float) $b->host_amount,
            'cancellation_policy' => self::policy($b->cancellation_policy),
            'payment_due_at' => $b->payment_due_at?->toISOString(),
            'payment_note' => $b->payment_note,
            'paid_at' => $b->paid_at?->toISOString(),
            'payment_method' => $b->payment_method,
            'decline_reason' => $b->decline_reason,
            'cancel_reason' => $b->cancel_reason,
            'refund_amount' => (float) $b->refund_amount,
            'refund_status' => $b->refund_status,
            'refund_account_set' => $b->refund_account !== null,
            'refunded_at' => $b->refunded_at?->toISOString(),
            'can_pay' => $b->canPay(),
            'can_cancel' => $b->canCancel(),
            'can_review' => $b->canReview() && ! ($b->relationLoaded('review') ? $b->review !== null : $b->review()->exists()),
            'review' => $b->relationLoaded('review') && $b->review ? self::review($b->review) : null,
            'trip_booking_ref' => $b->relationLoaded('tripBooking') ? $b->tripBooking?->booking_ref : null,
        ];
    }

    /** ใบจองในมุมของเจ้าของที่พัก */
    public static function hostBooking(StayBooking $b): array
    {
        return self::bookingBase($b) + [
            'host_amount' => (float) $b->host_amount,
            'paid' => $b->isPaid(),
        ];
    }

    /** ใบจองในมุมของหลังบ้าน */
    public static function adminBooking(StayBooking $b, bool $detail = false): array
    {
        $row = self::bookingBase($b) + [
            'stay' => $b->stay ? ['id' => $b->stay->id, 'name' => $b->stay->name, 'slug' => $b->stay->slug] : null,
            'user' => $b->user ? ['id' => $b->user->id, 'name' => $b->user->name, 'email' => $b->user->email, 'phone' => $b->user->phone] : null,
            'guest_email' => $b->guest_email,
            'commission_percent' => (float) $b->commission_percent,
            'commission_amount' => (float) $b->commission_amount,
            'host_amount' => (float) $b->host_amount,
            'payment_due_at' => $b->payment_due_at?->toISOString(),
            'paid_at' => $b->paid_at?->toISOString(),
            'paid_amount' => (float) $b->paid_amount,
            // promptpay = โอน+สลิป · beam_* = จ่ายผ่าน Beam (คืนเงินผ่าน Beam ได้ถ้าเป็นบัตร)
            'payment_method' => $b->payment_method,
            'slip_ocr_status' => $b->slip_ocr_status,
            'refund_amount' => (float) $b->refund_amount,
            'refund_status' => $b->refund_status,
            'payout_id' => $b->payout_id,
            'payout_eligible_at' => $b->payout_eligible_at?->toISOString(),
        ];

        if (! $detail) {
            return $row;
        }

        return $row + [
            'nightly_prices' => $b->nightly_prices ?? [],
            'room_amount' => (float) $b->room_amount,
            'extra_guest_amount' => (float) $b->extra_guest_amount,
            'experiences' => $b->experiences ?? [],
            'experiences_amount' => (float) $b->experiences_amount,
            'cancellation_policy' => self::policy($b->cancellation_policy),
            'responded_at' => $b->responded_at?->toISOString(),
            'responded_by' => $b->responded_by,
            'decline_reason' => $b->decline_reason,
            'slip_url' => $b->slip_path ? MediaDisk::slipUrl($b->slip_path) : null,
            'slip_ocr_result' => $b->slip_ocr_result,
            'payment_ref' => $b->payment_ref,
            'payment_note' => $b->payment_note,
            'cancelled_at' => $b->cancelled_at?->toISOString(),
            'cancelled_by' => $b->cancelled_by,
            'cancel_reason' => $b->cancel_reason,
            'refund_account' => $b->refund_account,
            'refunded_at' => $b->refunded_at?->toISOString(),
            'refund_note' => $b->refund_note,
            'completed_at' => $b->completed_at?->toISOString(),
            'admin_note' => $b->admin_note,
            'trip_booking_ref' => $b->tripBooking?->booking_ref,
        ];
    }

    private static function bookingBase(StayBooking $b): array
    {
        return [
            'id' => $b->id,
            'ref' => $b->ref,
            'status' => $b->status,
            'status_label' => $b->statusLabel(),
            'booking_mode' => $b->booking_mode,
            'room_type_id' => $b->room_type_id,
            'room_name' => $b->room_name,
            'check_in' => $b->check_in?->toDateString(),
            'check_out' => $b->check_out?->toDateString(),
            'nights' => (int) $b->nights,
            'units' => (int) $b->units,
            'guests' => (int) $b->guests,
            'guest_name' => $b->guest_name,
            'guest_phone' => $b->guest_phone,
            'note' => $b->note,
            'total_amount' => (float) $b->total_amount,
            'created_at' => $b->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<int, string>|null  $keys
     * @return array<int, array{key: string, label: string, icon: string}>
     */
    public static function amenities(?array $keys): array
    {
        $known = (array) config('stays.amenities', []);

        return collect($keys ?? [])
            ->filter(fn ($k) => isset($known[$k]))
            ->map(fn ($k) => ['key' => $k, 'label' => $known[$k][0], 'icon' => $known[$k][1]])
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, label: string, icon: string}> */
    public static function amenityOptions(): array
    {
        return self::amenities(array_keys((array) config('stays.amenities', [])));
    }
}
