<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ScheduleItineraryItem;

/**
 * รายละเอียดการจองสำหรับคนที่ยังไม่ได้ล็อกอิน (หน้า "ค้นหาการจอง")
 *
 * มีสองระดับ เพราะสองทางค้นหาพิสูจน์ตัวตนไม่เท่ากัน:
 *
 * - `$full = true` — ค้นด้วยรหัสการจอง + เบอร์โทร (guest-lookup) ผู้ค้นต้องถือ
 *   รหัสการจองอยู่ในมือ จึงเห็นได้ทั้ง QR เช็คอิน ลิงก์แชร์ตำแหน่งรถ และตัวเลขเงิน
 * - `$full = false` — ค้นด้วยชื่อ + เบอร์โทร (guest-lookup-by-name) ใครก็ตามที่รู้
 *   ชื่อกับเบอร์ของคนอื่นค้นได้ จึงตัดสิ่งที่ใช้ "ทำ" อะไรต่อได้ (รหัสการจอง, QR,
 *   ลิงก์แชร์) และตัดจำนวนเงินออก เหลือแต่สถานะว่าชำระครบแล้วหรือยัง
 *
 * ไม่ว่าระดับไหนก็ไม่ส่งข้อมูลที่เข้ารหัสไว้ในฐานข้อมูล (บัตรประชาชน พาสปอร์ต
 * ประวัติสุขภาพ การแพ้อาหาร) และเบอร์โทรผู้เดินทางถูกปิดกลางเสมอ
 */
class GuestBookingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Booking $booking, bool $full): array
    {
        $schedule = $booking->schedule;
        $trip = $schedule?->trip;
        $vehicle = $schedule?->vehicle;

        return [
            // ── คีย์เดิมที่หน้าติดตามรถของแอปอ่านอยู่ (BookingInfo.fromJson) ──
            'booking_ref' => $full ? $booking->booking_ref : null,
            'status' => $booking->status,
            'qr_code' => $full ? $booking->qr_code : null,
            'trip_title' => $trip?->title ?? '',
            'departure_point' => $trip?->departure_point ?? '',
            'departure_date' => $schedule?->departure_date?->toDateString() ?? '',
            'departs_at' => $schedule?->departs_at?->format('Y-m-d H:i:s'),
            'schedule_id' => $booking->schedule_id,
            'vehicle_id' => $schedule?->vehicle_id,
            'driver_name' => $vehicle?->driver_name,
            'driver_phone' => $vehicle?->driver_phone,
            'license_plate' => $vehicle?->license_plate,
            'share_url' => $full ? $booking->shareUrl() : null,

            // ── รายละเอียดเต็ม ──
            'trip' => self::trip($booking),
            'schedule' => self::schedule($booking),
            'pickup' => self::pickup($booking),
            'vehicle' => self::vehicle($booking),
            'staff' => self::staff($booking, $full),
            'passengers' => self::passengers($booking),
            'payment' => self::payment($booking, $full),
            'itinerary' => self::itinerary($booking),
            'checked_in' => (bool) $booking->checked_in,
            'checked_in_at' => $booking->checked_in_at?->toISOString(),
            'booked_at' => $booking->created_at?->toISOString(),
            'group_name' => $booking->is_group ? $booking->group_name : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function trip(Booking $booking): array
    {
        $trip = $booking->schedule?->trip;

        return [
            'title' => $trip?->title,
            'slug' => $trip?->slug,
            'location' => $trip?->location,
            'type' => $trip?->type,
            'difficulty' => $trip?->difficulty,
            'duration_days' => $trip?->duration_days,
            'destination_type' => $trip?->destination_type,
            'country_code' => $trip?->country_code,
            'departure_point' => $trip?->departure_point,
            // ส่งค่าดิบเหมือน TripResource — ฝั่งแอปต่อ base URL เองด้วย ApiConfig.mediaUrl
            'cover_image' => $trip?->cover_image,
            'thumbnail_image' => $trip?->thumbnail_image,
            'latitude' => $trip?->latitude,
            'longitude' => $trip?->longitude,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schedule(Booking $booking): array
    {
        $schedule = $booking->schedule;

        return [
            'id' => $schedule?->id,
            'departure_date' => $schedule?->departure_date?->toDateString(),
            'return_date' => $schedule?->return_date?->toDateString(),
            'departs_at' => $schedule?->departs_at?->format('Y-m-d H:i:s'),
            'transport_type' => $schedule?->transport_type,
            // รอบที่บินไป: ไม่มีจุดรับ มีจุดนัดพบที่สนามบินแทน
            'meeting_point' => $schedule?->meeting_point,
            'meeting_time' => $schedule?->meeting_time,
            'meeting_map_url' => $schedule?->meeting_map_url,
            'baggage_allowance' => $schedule?->baggage_allowance,
            'flights' => $schedule?->flights,
        ];
    }

    /**
     * จุดขึ้นรถของการจอง — จุดที่ผู้จัดกำหนด หรือหมุดที่ลูกค้าปักเอง
     *
     * @return array<string, mixed>|null
     */
    private static function pickup(Booking $booking): ?array
    {
        $point = $booking->pickupPoint;

        if ($point) {
            return [
                'kind' => 'point',
                'region' => $point->region,
                'region_label' => $point->region_label,
                'location' => $point->pickup_location,
                'pickup_time' => $point->pickup_time,
                'map_url' => $point->map_url,
                'image_url' => $point->image_url,
                'notes' => $point->notes,
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
            ];
        }

        if ($booking->custom_pickup_status !== null) {
            return [
                'kind' => 'custom',
                'location' => $booking->custom_pickup_label,
                'notes' => $booking->custom_pickup_note,
                'status' => $booking->custom_pickup_status,
                'reject_reason' => $booking->custom_pickup_reject_reason,
                'latitude' => $booking->custom_pickup_lat,
                'longitude' => $booking->custom_pickup_lng,
            ];
        }

        return $booking->pickup_region !== null
            ? ['kind' => 'region', 'region' => $booking->pickup_region]
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function vehicle(Booking $booking): ?array
    {
        $vehicle = $booking->schedule?->vehicle;

        if (! $vehicle) {
            return null;
        }

        return [
            'name' => $vehicle->name,
            'type' => $vehicle->type,
            'color' => $vehicle->color,
            'license_plate' => $vehicle->license_plate,
            'driver_name' => $vehicle->driver_name,
            'driver_phone' => $vehicle->driver_phone,
        ];
    }

    /**
     * ทีมงานที่ดูแลรอบนี้ — เบอร์ติดต่อให้เฉพาะคนที่ถือรหัสการจอง
     *
     * @return list<array<string, mixed>>
     */
    private static function staff(Booking $booking, bool $full): array
    {
        $schedule = $booking->schedule;

        if (! $schedule || ! $schedule->relationLoaded('activeStaff')) {
            return [];
        }

        return $schedule->activeStaff
            ->map(fn ($staff) => [
                'name' => $staff->nickname ?: $staff->name,
                'phone' => $full ? $staff->phone : null,
                'avatar_url' => $staff->avatar_url,
            ])
            ->values()
            ->all();
    }

    /**
     * ผู้เดินทางในการจอง — ชื่อ ที่นั่ง จุดขึ้นรถรายคน
     * เบอร์โทรปิดกลางเสมอ ส่วนข้อมูลที่เข้ารหัสไว้ (บัตร/พาสปอร์ต/สุขภาพ) ไม่ส่งเลย
     *
     * @return list<array<string, mixed>>
     */
    private static function passengers(Booking $booking): array
    {
        $seats = $booking->relationLoaded('seats') ? $booking->seats : collect();

        return $booking->passengers
            ->values()
            ->map(function (BookingPassenger $passenger, int $index) use ($seats) {
                $seat = $seats->firstWhere('passenger_name', $passenger->name)
                    ?? $seats->get($index);

                return [
                    'name' => trim(($passenger->title ? $passenger->title.' ' : '').$passenger->name),
                    'nickname' => $passenger->nickname,
                    'phone' => self::maskPhone($passenger->phone),
                    'seat' => $seat?->seat_id,
                    'halal_food' => (bool) $passenger->halal_food,
                    'pickup_location' => $passenger->pickupPoint?->pickup_location,
                    'pickup_time' => $passenger->pickupPoint?->pickup_time,
                ];
            })
            ->all();
    }

    /**
     * สรุปการชำระเงิน — จำนวนเงินให้เฉพาะทางค้นที่ต้องมีรหัสการจอง
     *
     * @return array<string, mixed>
     */
    private static function payment(Booking $booking, bool $full): array
    {
        $outstanding = max(0, (float) $booking->total_amount - (float) $booking->paid_amount);

        $summary = [
            'payment_type' => $booking->payment_type ?? 'full',
            'is_fully_paid' => $booking->isFullyPaid(),
            'has_outstanding' => $outstanding > 0,
            'balance_due_at' => $booking->balance_due_at?->toISOString(),
            'balance_paid_at' => $booking->balance_paid_at?->toISOString(),
            'paid_at' => $booking->paid_at?->toISOString(),
            'payment_method' => $booking->payment_method,
            // สลิปเข้ามาแล้วแต่ยอดไม่ตรง — ที่นั่งถูกถือไว้รอแอดมินตรวจ
            'slip_under_review' => $booking->status === 'pending' && $booking->slip_ocr_status !== null,
            'amounts_hidden' => ! $full,
        ];

        if (! $full) {
            return $summary;
        }

        return $summary + [
            'total_amount' => (float) $booking->total_amount,
            'paid_amount' => (float) $booking->paid_amount,
            'outstanding_amount' => $outstanding,
            'deposit_amount' => $booking->deposit_amount !== null ? (float) $booking->deposit_amount : null,
            'balance_amount' => $booking->balance_amount !== null ? (float) $booking->balance_amount : null,
            'discount_amount' => $booking->discount_amount !== null ? (float) $booking->discount_amount : null,
            'promotion_code' => $booking->promotion_code,
            'flexi_surcharge' => $booking->flexi_surcharge !== null ? (float) $booking->flexi_surcharge : null,
            'addons' => self::lineItems($booking->selected_addons),
            'addons_total' => (float) $booking->addons_total,
            'rentals' => self::lineItems($booking->selected_rentals),
            'rentals_total' => (float) $booking->rentals_total,
            'installments' => self::installments($booking),
            'refund_status' => $booking->refund_status,
            'refund_amount' => $booking->refund_amount !== null ? (float) $booking->refund_amount : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function installments(Booking $booking): array
    {
        if (! $booking->relationLoaded('installmentPayments')) {
            return [];
        }

        return $booking->installmentPayments
            ->map(fn ($installment) => [
                'installment_no' => $installment->installment_no,
                'amount' => (float) $installment->amount,
                'due_date' => $installment->due_date?->toDateString(),
                'status' => $installment->status,
                'paid_at' => $installment->paid_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    /**
     * แถวของแถมและอุปกรณ์เช่าที่เลือกไว้ — เก็บเป็น JSON คนละรูปแบบกันตามยุคที่บันทึก
     *
     * @return list<array<string, mixed>>
     */
    private static function lineItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $key => $item) {
            if (is_string($item)) {
                $items[] = ['name' => $item, 'quantity' => 1, 'price' => null];

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'name' => $item['name'] ?? (is_string($key) ? $key : ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => isset($item['price']) ? (float) $item['price'] : null,
            ];
        }

        return $items;
    }

    /**
     * กำหนดการของรอบ — ผู้จัดเขียนไว้ ไม่มีข้อมูลส่วนตัวของใคร
     *
     * @return list<array<string, mixed>>
     */
    private static function itinerary(Booking $booking): array
    {
        $schedule = $booking->schedule;

        if (! $schedule || ! $schedule->relationLoaded('itineraryItems')) {
            return [];
        }

        return $schedule->itineraryItems
            ->map(fn (ScheduleItineraryItem $item) => [
                'item_date' => $item->item_date?->toDateString(),
                'time' => $item->time,
                'title' => $item->title,
                'detail' => $item->detail,
            ])
            ->values()
            ->all();
    }

    /**
     * 0812345678 → 081-xxx-5678 (พอให้เจ้าตัวรู้ว่าเบอร์ไหน แต่ลอกไปใช้ต่อไม่ได้)
     */
    private static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '' || strlen($digits) < 7) {
            return null;
        }

        return substr($digits, 0, 3).'-xxx-'.substr($digits, -4);
    }
}
