<?php

namespace App\Http\Resources;

use App\Models\BookingPassenger;
use App\Models\TripSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class TripScheduleResource extends JsonResource
{
    /**
     * นำเสนอชื่อผู้ร่วมเดินทางแบบสั้น: ชื่อเล่นถ้ามี ไม่งั้นใช้ชื่อต้นคำเดียว
     * เพื่อไม่เปิดเผยชื่อ-นามสกุลเต็มของลูกค้าคนอื่นในรอบ
     */
    protected function travelerDisplayName(BookingPassenger $passenger): string
    {
        $nickname = trim((string) $passenger->nickname);
        if ($nickname !== '') {
            return $nickname;
        }

        return Str::of((string) $passenger->name)->trim()->explode(' ')->first() ?: 'ผู้ร่วมเดินทาง';
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'trip' => new TripResource($this->whenLoaded('trip')),
            'departure_date' => $this->departure_date?->toDateString(),
            // เวลาออกเดินทางจริง (เวลาท้องถิ่นไทย ไม่แปลง timezone) — อาจอยู่ก่อน
            // departure_date เช่น รถออกคืนวันศุกร์ 23:30 สำหรับทริปวันเสาร์
            'departs_at' => $this->departs_at?->format('Y-m-d H:i:s'),
            'return_date' => $this->return_date?->toDateString(),
            'review_available_at' => ($this->return_date || $this->departure_date)
                ? $this->reviewAvailableAt()->toISOString()
                : null,
            'total_seats' => $this->total_seats,
            'booked_seats' => $this->booked_seats,
            'available_seats' => $this->available_seats,
            // ที่นั่งที่จองได้จริงตอนนี้ = ว่างทั้งหมด − ที่กันไว้ให้คิวรอที่ได้รับสิทธิ์
            // (เท่ากับ available_seats เสมอถ้าไม่มีใครถือสิทธิ์อยู่) UI ควรอ่านค่านี้
            // เพื่อไม่ให้ลูกค้ากดจนถึงขั้นตอนสุดท้ายแล้วค่อยถูกปฏิเสธ
            'bookable_seats' => $this->bookable_seats,
            'held_seats' => (int) ($this->held_seats ?? 0),
            // ระบบสถานะการันตีออกเดินทาง — waiting / almost_ready / guaranteed
            // (null สำหรับทริปเหมาคัน) พร้อมเกณฑ์ที่นั่งเพื่อให้แอปเรนเดอร์
            // "ขาดอีก X ที่นั่ง" ได้เองโดยไม่ต้อง hardcode
            'departure_status' => $this->departureStatus(),
            'seats_to_guarantee' => $this->seatsToGuarantee(),
            'guarantee_min_seats' => TripSchedule::guaranteeMinSeats(),
            'almost_ready_min_seats' => TripSchedule::ALMOST_READY_MIN_SEATS,
            'active_bookings_count' => $this->when(
                isset($this->active_bookings_count),
                fn () => (int) $this->active_bookings_count
            ),
            'assigned_staff_count' => $this->when(
                isset($this->assigned_staff_count),
                fn () => (int) $this->assigned_staff_count
            ),
            'photos_count' => $this->when(
                isset($this->photos_count),
                fn () => (int) $this->photos_count
            ),
            // มีลิงก์อัลบั้มสาธารณะเปิดอยู่หรือไม่ — เฉพาะฝั่งแอดมินเท่านั้น
            // (ส่งแค่สถานะ ไม่ส่งตัว token)
            'photos_shared' => $this->when(
                (bool) $request->user()?->hasAnyRole(['admin', 'operator']),
                fn () => filled($this->photo_token)
            ),
            'transport_type' => $this->transport_type,
            // รอบที่บินไปไม่มีผังที่นั่งให้เลือก — สายการบินจัดที่นั่งเอง แล้วทีมงาน
            // ค่อยกรอกเลขที่นั่งจริงกลับเข้าการจอง
            'allows_seat_selection' => $this->allowsSeatSelection(),
            // รอบที่บินไปไม่มีจุดขึ้นรถ — จุดนัดพบที่สนามบิน + ขาบินคือสิ่งที่มาแทน
            // ส่งเป็นก้อนเดียวเพื่อให้ client เช็คที่เดียวว่ามีข้อมูลนี้ไหม
            'flight_plan' => $this->when(
                $this->isFlight(),
                fn () => [
                    'meeting_point' => $this->meeting_point,
                    'meeting_map_url' => $this->meeting_map_url,
                    'meeting_time' => $this->meeting_time,
                    // เวลานัดพบเต็มรูปแบบ (เวลาไทย) — client ไม่ต้องเดาว่า meeting_time
                    // อยู่วันไหนเมื่อไฟลต์ออกหลังเที่ยงคืน
                    'meeting_at' => $this->meetingAt()?->format('Y-m-d H:i:s'),
                    'baggage_allowance' => $this->baggage_allowance,
                    'legs' => $this->flightLegs(),
                ],
            ),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'status' => $this->status,
            'price' => $this->effective_price,
            // Pre-discount price (struck through in the UI) and the live flash-sale
            // block, present only when the admin has enabled a flash sale.
            'original_price' => $this->original_price,
            'flash_sale' => $this->when(
                (bool) $this->flash_sale_enabled,
                fn () => [
                    'active' => $this->flashSaleActive(),
                    // Waiting for its scheduled start; the customer UI keys off
                    // `active`, so an upcoming sale renders as the normal price.
                    'upcoming' => $this->flashSaleUpcoming(),
                    'price' => (float) $this->flash_sale_price,
                    'starts_at' => $this->flash_sale_starts_at?->toISOString(),
                    'ends_at' => $this->flash_sale_ends_at?->toISOString(),
                    'discount_percent' => $this->original_price > 0
                        ? (int) round(($this->original_price - (float) $this->flash_sale_price) / $this->original_price * 100)
                        : 0,
                ]
            ),
            'installment_enabled' => (bool) $this->installment_enabled,
            'installment_count' => $this->installment_count,
            'installment_interval_days' => $this->installment_interval_days,
            'deposit_enabled' => (bool) $this->deposit_enabled,
            'deposit_type' => $this->deposit_type,
            'deposit_amount' => $this->deposit_amount,
            'deposit_percent' => $this->deposit_percent,
            'join_trip_enabled' => (bool) $this->join_trip_enabled,
            'join_trip_price' => $this->join_trip_price,
            'is_charter' => (bool) $this->is_charter,
            'weather' => $this->when(
                isset($this->weather_forecast),
                fn () => $this->weather_forecast
            ),
            // ผู้ร่วมเดินทางทั้งหมดในรอบนี้ (ทุกการจองที่ยัง active) — ใช้แสดง
            // อวาตาร์ผู้ร่วมทริปบนการ์ดการจอง เปิดเผยเฉพาะชื่อเล่น/ชื่อต้นเพื่อความเป็นส่วนตัว
            'travelers' => $this->when(
                $this->relationLoaded('bookings'),
                fn () => $this->bookings
                    ->flatMap(fn ($booking) => $booking->relationLoaded('passengers')
                        ? $booking->passengers->map(fn ($p) => [
                            'name' => $this->travelerDisplayName($p),
                            'is_self' => $request->user() !== null
                                && $booking->user_id === $request->user()->id,
                        ])
                        : [])
                    ->values()
            ),
            'pickup_points' => SchedulePickupPointResource::collection($this->whenLoaded('pickupPoints')),
            // เส้นทางเดินรถที่แอดมินวาดเอง (จุดดิบสำหรับ editor; ลูกค้าได้ polyline
            // ที่ encode แล้วผ่าน /schedules/{id}/route อยู่แล้ว จึงไม่ใช่ความลับ)
            'custom_route' => $this->custom_route,
            'waitlist_count' => $this->when(
                isset($this->waitlist_count),
                fn () => (int) $this->waitlist_count
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
