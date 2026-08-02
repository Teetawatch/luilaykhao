<?php

namespace App\Services;

use App\Jobs\ProcessWaitlistJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Promotion;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\CustomPickupPricing;
use App\Support\ThaiDate;
use App\Traits\RemapsBookingPickup;
use Illuminate\Support\Facades\DB;

class BookingService
{
    use RemapsBookingPickup;

    public function __construct(
        private SeatLockService $seatLockService,
        private MailService $mailService,
        private SmsService $smsService,
        private WaitlistService $waitlistService,
        private ReferralService $referralService,
        private BroadcastNotificationService $broadcastService,
        private TripAlertService $tripAlertService,
        private ScheduleSeatNotifier $seatNotifier,
    ) {}

    public function createBooking(
        int $userId,
        int $scheduleId,
        array $passengers,
        array $seatIds = [],
        ?int $pickupPointId = null,
        ?string $pickupRegion = null,
        bool $isGroup = false,
        ?string $groupName = null,
        ?string $groupNotes = null,
        ?string $promotionCode = null,
        bool $isJoinTrip = false,
        array $selectedAddons = [],
        array $selectedRentals = [],
        ?array $customPickup = null,
        bool $verifySeatLocks = true,
        bool $isGift = false,
        ?string $giftFromName = null,
        ?string $giftMessage = null,
    ): Booking {
        // Whether THIS booking is the one that sold out the schedule — drives
        // the "trip is now full" admin push sent after the transaction commits.
        $scheduleBecameFull = false;
        // Seats left right after this booking — drives the real-time "almost
        // sold out" customer blast (3-2-1 left). Null = join trip / not counted.
        $availableAfterBooking = null;
        // Booked-seat count before/after this booking — drives the real-time
        // "ระบบสถานะการันตีออกเดินทาง" push when the round crosses into the
        // Almost Ready (5-7) or Guaranteed (8+) band. Null = join trip.
        $bookedBeforeBooking = null;
        $bookedAfterBooking = null;

        $booking = DB::transaction(function () use ($userId, $scheduleId, $passengers, $seatIds, $pickupPointId, $pickupRegion, $isGroup, $groupName, $groupNotes, $promotionCode, $isJoinTrip, $selectedAddons, $selectedRentals, $customPickup, $verifySeatLocks, $isGift, $giftFromName, $giftMessage, &$scheduleBecameFull, &$availableAfterBooking, &$bookedBeforeBooking, &$bookedAfterBooking) {
            $schedule = TripSchedule::with('trip')->lockForUpdate()->findOrFail($scheduleId);
            $schedule->syncBookedSeats();

            if ($schedule->is_charter) {
                throw new \Exception('รอบเดินทางนี้เป็นรอบเหมา ไม่สามารถจองได้');
            }

            // รอบที่ตั้งเวลาเปิดจองไว้ — สมาชิกระดับสูงเข้าได้ก่อนตามชั่วโมงของระดับ
            if (! $schedule->isBookableBy($userId)) {
                $opensAt = $schedule->bookingOpensAtFor($userId);

                throw new \Exception(
                    'รอบนี้ยังไม่เปิดจอง จะเปิดให้คุณจองได้วันที่ '
                    .ThaiDate::short($opensAt).' เวลา '.$opensAt->timezone('Asia/Bangkok')->format('H:i').' น.'
                );
            }

            if ($isJoinTrip && ! $schedule->join_trip_enabled) {
                throw new \Exception('รอบเดินทางนี้ไม่เปิดให้จองแบบ Join Trip');
            }

            $participantCount = count($passengers);

            // Join trip allows unlimited bookings — skip seat availability check
            if (! $isJoinTrip && $schedule->available_seats < $participantCount) {
                throw new \Exception('ที่นั่งไม่เพียงพอ');
            }

            // Verify seat locks if seat-based booking and NOT join trip
            if (! $isJoinTrip && ! empty($seatIds)) {
                // กันที่นั่งซ้ำ "ภายในคำขอเดียวกัน" — ถ้า seat_ids มี A2 ซ้ำสองครั้ง
                // insert ตัวที่สองจะชนตัวแรกในธุรกรรมเดียวกัน (unique constraint) แล้ว rollback ทั้งก้อน
                $duplicateSeatIds = collect($seatIds)
                    ->duplicates()
                    ->unique()
                    ->values();

                if ($duplicateSeatIds->isNotEmpty()) {
                    throw new \Exception('เลือกที่นั่งซ้ำกัน: '.$duplicateSeatIds->join(', ').' กรุณาเลือกที่นั่งใหม่');
                }

                // ตรวจ Redis soft-lock เฉพาะ flow ที่ผู้ใช้ล็อกที่นั่งเองก่อนจอง (เช่น booking flow ปกติ)
                // สำหรับ group checkout การจองที่นั่งถูกยึดถาวรไว้ใน group_plan_members แล้ว Redis lock เป็นแค่
                // ตัวช่วยชั่วคราวที่อาจหมดอายุ/ถูก evict ระหว่างที่กลุ่มรวมตัวกัน จึงข้ามได้ — การกันจองซ้ำจริง
                // อาศัย DB guard ด้านล่าง (booking_seats + lockForUpdate ของ schedule) ซึ่งเป็น source of truth
                if ($verifySeatLocks) {
                    foreach ($seatIds as $seatId) {
                        if (! $this->seatLockService->isLockedByUser($scheduleId, $seatId, $userId)) {
                            throw new \Exception("ที่นั่ง {$seatId} ไม่ได้ถูกล็อคโดยคุณ");
                        }
                    }
                }

                // ตรวจกับ booking_seats ซึ่งเป็น source of truth จริง — Redis lock เป็นของชั่วคราว
                // (TTL หมด/ล่ม/ถูก forceUnlock หลังจองสำเร็จ) จึงกันที่นั่งซ้ำไม่ได้เสมอ
                // เราถือ lockForUpdate ของ schedule อยู่แล้ว การอ่านตรงนี้จึง race-safe กับการจองพร้อมกันบนรอบเดียวกัน
                $alreadyBooked = BookingSeat::where('schedule_id', $scheduleId)
                    ->whereIn('seat_id', $seatIds)
                    ->whereHas('booking', fn ($query) => $query
                        ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
                    ->pluck('seat_id')
                    ->unique()
                    ->values();

                if ($alreadyBooked->isNotEmpty()) {
                    throw new \Exception('ที่นั่ง '.$alreadyBooked->join(', ').' ถูกจองไปแล้ว กรุณาเลือกที่นั่งอื่น');
                }
            }

            // Use join trip price if applicable
            if ($isJoinTrip) {
                $pricePerPerson = $schedule->join_trip_price ?? $schedule->effective_price;
                $pickupPoint = null;
                $pickupRegion = null; // Join trip might not need pickup region if they meet at destination?
                // But user didn't specify. I'll keep pickup logic if they provided it.
            } else {
                $defaultPrice = $schedule->effective_price;
                $pickupPoint = null;

                if ($pickupPointId) {
                    $pickupPoint = SchedulePickupPoint::where('id', $pickupPointId)
                        ->where('schedule_id', $scheduleId)
                        ->first();
                } elseif ($pickupRegion) {
                    $pickupPoint = SchedulePickupPoint::where('schedule_id', $scheduleId)
                        ->where('region', $pickupRegion)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first();
                }

                if ($pickupPoint) {
                    $defaultPrice = $pickupPoint->price;
                    $pickupRegion = $pickupPoint->region;
                } elseif (self::hasCustomPin($customPickup)) {
                    // หมุดที่ปักเองไม่มีราคาของตัวเอง ราคาจึงเคยร่วงกลับไปเป็นราคาฐาน
                    // ของรอบ — คิดเท่าจุดรับที่ใกล้หมุดที่สุดแทน (ขั้นต่ำ = ราคารอบ)
                    $defaultPrice = CustomPickupPricing::resolvePrice(
                        (float) $schedule->effective_price,
                        $schedule->pickupPoints,
                        (float) $customPickup['lat'],
                        (float) $customPickup['lng'],
                    );
                }

                // Resolve per-passenger pickup points; fall back to booking-level pickup
                $passengerPickupPoints = [];
                foreach ($passengers as $passengerData) {
                    $pPickupId = $passengerData['pickup_point_id'] ?? null;
                    if ($pPickupId && $pPickupId !== $pickupPointId) {
                        $pp = SchedulePickupPoint::where('id', $pPickupId)
                            ->where('schedule_id', $scheduleId)
                            ->first();
                        $passengerPickupPoints[] = $pp ?? $pickupPoint;
                    } else {
                        $passengerPickupPoints[] = $pickupPoint;
                    }
                }

                $pricePerPerson = $defaultPrice; // kept for join-trip path compatibility
            }

            // รายการเสริมเลือกได้เป็นจำนวน (เช่น ไป 4 คน แต่เช่าเสื่อแค่ 2 ผืน)
            // payload เก่าที่ส่งมาเป็น index เปล่า ๆ ยังใช้ได้ และคิดจำนวนตามชนิด
            // ราคาเหมือนเดิม: ต่อคน = จำนวนผู้เดินทาง, ต่อการจอง = 1
            $addonSelections = collect($selectedAddons)
                ->map(function ($addon) {
                    if (is_array($addon)) {
                        return [
                            'index' => (int) ($addon['index'] ?? -1),
                            'quantity' => isset($addon['quantity']) ? (int) $addon['quantity'] : null,
                        ];
                    }

                    return ['index' => (int) $addon, 'quantity' => null];
                })
                ->filter(fn ($addon) => $addon['quantity'] === null || $addon['quantity'] > 0)
                ->keyBy('index')
                ->values();
            $addonOptions = collect($schedule->trip?->must_know['items'] ?? [])->values();
            $selectedAddonSnapshots = [];
            $addonsTotal = 0;

            foreach ($addonSelections as $selection) {
                $option = $addonOptions->get($selection['index']);
                if (! $option || ! is_array($option) || empty($option['name'])) {
                    throw new \Exception('รายการเสริมที่เลือกไม่ถูกต้อง');
                }

                $unitPrice = (float) ($option['price'] ?? 0);
                $priceType = ($option['price_type'] ?? 'per_booking') === 'per_person'
                    ? 'per_person'
                    : 'per_booking';
                $quantity = $selection['quantity']
                    ?? ($priceType === 'per_person' ? $participantCount : 1);

                if ($priceType === 'per_person' && $quantity > $participantCount) {
                    throw new \Exception('จำนวนรายการเสริมต่อคนมากกว่าจำนวนผู้เดินทาง');
                }

                $totalPrice = $unitPrice * $quantity;
                $addonsTotal += $totalPrice;

                $selectedAddonSnapshots[] = [
                    'name' => (string) $option['name'],
                    'unit_price' => $unitPrice,
                    'price_type' => $priceType,
                    'quantity' => $quantity,
                    'total_price' => $totalPrice,
                ];
            }

            // Equipment rentals — chosen by index into trip.rental_items with a
            // per-item quantity (e.g. 2 sleeping bags). Snapshotted like add-ons.
            $rentalOptions = collect($schedule->trip?->rental_items ?? [])->values();
            $selectedRentalSnapshots = [];
            $rentalsTotal = 0;

            foreach ($selectedRentals as $rental) {
                $rentalIndex = (int) ($rental['index'] ?? -1);
                $quantity = (int) ($rental['quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }

                $option = $rentalOptions->get($rentalIndex);
                if (! $option || ! is_array($option) || empty($option['name'])) {
                    throw new \Exception('อุปกรณ์เช่าที่เลือกไม่ถูกต้อง');
                }

                $unitPrice = (float) ($option['price'] ?? 0);
                $totalPrice = $unitPrice * $quantity;
                $rentalsTotal += $totalPrice;

                $selectedRentalSnapshots[] = [
                    'name' => (string) $option['name'],
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'total_price' => $totalPrice,
                    'image_url' => (string) ($option['image_url'] ?? ''),
                ];
            }

            if ($isJoinTrip) {
                $passengersSubtotal = $pricePerPerson * $participantCount;
            } else {
                $passengersSubtotal = array_sum(array_map(
                    fn ($pp) => (float) ($pp?->price ?? $defaultPrice),
                    $passengerPickupPoints
                ));
            }

            $totalAmount = $passengersSubtotal + $addonsTotal + $rentalsTotal;

            // ... rest of the logic remains the same until Booking::create
            // I need to include the rest of the logic here because I'm replacing a large block.

            $promotionId = null;
            $discountAmount = 0;

            // คูปองส่วนบุคคล (แลกด้วยแต้ม หรือของขวัญวันเกิด) ใช้ช่องกรอกโค้ดเดียวกับ
            // โค้ดโปรโมชัน — เดิมช่องนี้ดูแค่ตาราง promotions คูปองที่ลูกค้าแลกมาจึง
            // ใช้ไม่ได้เลยสักใบ
            $redemption = $promotionCode
                ? LoyaltyRedemption::with('reward')
                    ->where('coupon_code', $promotionCode)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($redemption) {
                if (! $redemption->isUsableBy($userId)) {
                    throw new \Exception('คูปองนี้ใช้ไม่ได้ (อาจถูกใช้ไปแล้ว หมดอายุ หรือไม่ใช่ของบัญชีนี้)');
                }

                // ส่งยอดค่าเช่าอุปกรณ์ไปด้วย เพราะคูปองเช่าฟรีหักได้เฉพาะส่วนนั้น
                $discountAmount = $redemption->discountFor($totalAmount, $rentalsTotal);

                if ($discountAmount <= 0) {
                    throw new \Exception(
                        $redemption->rewardType() === LoyaltyReward::TYPE_FREE_RENTAL
                            ? 'คูปองนี้ใช้กับค่าเช่าอุปกรณ์ กรุณาเลือกอุปกรณ์ที่ต้องการเช่าก่อน'
                            : 'คูปองนี้ไม่มีส่วนลดเหลืออยู่'
                    );
                }

                $totalAmount -= $discountAmount;
            } elseif ($promotionCode) {
                $promotion = Promotion::where('code', $promotionCode)->where('is_active', true)->lockForUpdate()->first();
                if ($promotion) {
                    $isValid = true;
                    if ($promotion->start_date && now()->startOfDay()->lt($promotion->start_date)) {
                        $isValid = false;
                    }
                    if ($promotion->end_date && now()->startOfDay()->gt($promotion->end_date)) {
                        $isValid = false;
                    }
                    if ($promotion->max_uses && $promotion->used_count >= $promotion->max_uses) {
                        $isValid = false;
                    }
                    if ($promotion->trip_ids && is_array($promotion->trip_ids) && ! in_array($schedule->trip_id, $promotion->trip_ids)) {
                        $isValid = false;
                    }

                    if ($isValid) {
                        $promotionId = $promotion->id;
                        if ($promotion->type === 'percent') {
                            $discountAmount = ($totalAmount * $promotion->value) / 100;
                        } else {
                            $discountAmount = $promotion->value;
                        }

                        if ($discountAmount > $totalAmount) {
                            $discountAmount = $totalAmount;
                        }

                        $totalAmount -= $discountAmount;
                        $promotion->increment('used_count');
                    } else {
                        throw new \Exception('โค้ดส่วนลดไม่สามารถใช้งานได้');
                    }
                } else {
                    throw new \Exception('ไม่พบโค้ดส่วนลดนี้');
                }
            }

            // จุดรับแบบ custom (ลูกค้าปักหมุดเอง) จะถูกใช้ก็ต่อเมื่อไม่ได้เลือกจุดที่กำหนดไว้
            // และไม่ใช่ join trip — รับอัตโนมัติทันที ลูกค้าชำระเงินได้เลย ไม่มีค่าบริการ
            // แยกต่างหาก (custom_pickup_price) เพราะราคาโซนถูกคิดรวมในค่าทริปต่อคนแล้ว
            // (บันทึกตำแหน่งไว้ให้แอดมินเห็นเพื่อใช้จัดเส้นทางรับ)
            $useCustomPickup = ! $isJoinTrip && ! $pickupPoint && self::hasCustomPin($customPickup);

            $booking = Booking::create([
                'booking_ref' => Booking::generateRef(),
                'user_id' => $userId,
                'schedule_id' => $scheduleId,
                'pickup_region' => $pickupRegion,
                'pickup_point_id' => $pickupPoint?->id,
                'custom_pickup_label' => $useCustomPickup ? $customPickup['label'] : null,
                'custom_pickup_lat' => $useCustomPickup ? $customPickup['lat'] : null,
                'custom_pickup_lng' => $useCustomPickup ? $customPickup['lng'] : null,
                'custom_pickup_note' => $useCustomPickup ? ($customPickup['note'] ?? null) : null,
                'custom_pickup_status' => $useCustomPickup ? Booking::CUSTOM_PICKUP_APPROVED : null,
                'custom_pickup_price' => $useCustomPickup ? 0 : null,
                'custom_pickup_resolved_at' => $useCustomPickup ? now() : null,
                'is_group' => $isGroup || $participantCount > 1,
                'group_name' => $groupName,
                'group_notes' => $groupNotes,
                'qr_code' => Booking::generateQrCode(),
                'status' => 'pending',
                'total_amount' => $totalAmount,
                'selected_addons' => $selectedAddonSnapshots,
                'addons_total' => $addonsTotal,
                'selected_rentals' => $selectedRentalSnapshots,
                'rentals_total' => $rentalsTotal,
                'promotion_id' => $promotionId,
                'promotion_code' => ($promotionId || $redemption) ? $promotionCode : null,
                'discount_amount' => $discountAmount,
                'is_join_trip' => $isJoinTrip,
                'is_gift' => $isGift,
                'gift_code' => $isGift ? Booking::generateGiftCode() : null,
                'gift_from_name' => $isGift ? $giftFromName : null,
                'gift_message' => $isGift ? $giftMessage : null,
            ]);

            // ตัดคูปองทิ้งทันทีที่ผูกกับการจองแล้ว อยู่ใน transaction เดียวกับการ
            // สร้างการจอง คูปองใบเดียวจึงใช้ได้ครั้งเดียวแม้กดพร้อมกันสองหน้าต่าง
            if ($redemption) {
                $redemption->update([
                    'is_used' => true,
                    'booking_id' => $booking->id,
                ]);
            }

            // Create passengers
            foreach ($passengers as $index => $passengerData) {
                $resolvedPickupPoint = $isJoinTrip ? null : ($passengerPickupPoints[$index] ?? null);
                BookingPassenger::create([
                    'booking_id' => $booking->id,
                    'pickup_point_id' => $resolvedPickupPoint?->id,
                    ...array_diff_key($passengerData, ['pickup_point_id' => null]),
                ]);
            }

            // Create seats if seat-based and NOT join trip
            if (! $isJoinTrip && ! empty($seatIds)) {
                foreach ($seatIds as $index => $seatId) {
                    BookingSeat::create([
                        'booking_id' => $booking->id,
                        'schedule_id' => $scheduleId,
                        'seat_id' => $seatId,
                        'passenger_name' => $passengers[$index]['name'] ?? null,
                    ]);
                }
                foreach ($seatIds as $seatId) {
                    $this->seatLockService->forceUnlock($scheduleId, $seatId);
                }
            }

            if (! $isJoinTrip) {
                $bookedBeforeBooking = (int) $schedule->booked_seats;
                $schedule->increment('booked_seats', $participantCount);
                // increment() also updates the in-memory attribute, so the
                // available_seats accessor reflects the post-booking count.
                // We already passed the availability check above, so reaching
                // zero here means this booking is what filled the last seats.
                $scheduleBecameFull = $schedule->available_seats <= 0;
                $availableAfterBooking = $schedule->available_seats;
                $bookedAfterBooking = (int) $schedule->booked_seats;
            }

            $booking->load(['passengers.pickupPoint', 'seats', 'schedule.trip']);

            return $booking;
        });

        // Send emails outside of DB transaction
        $this->mailService->sendBookingCreatedEmail($booking);
        SmartNotification::send(
            $booking->user_id,
            'booking_created',
            'สร้างการจองสำเร็จ',
            "เลขการจอง {$booking->booking_ref} ถูกสร้างแล้ว กรุณาชำระเงินเพื่อยืนยันที่นั่ง",
            [
                'booking_ref' => $booking->booking_ref,
                'route' => 'booking',
            ],
        );

        // Mark user's waitlist entry as booked (if they came from the waitlist)
        $this->waitlistService->markBooked($userId, $scheduleId);

        // แจ้งเตือนที่นั่ง: เต็ม (staff FCM + ชวนเข้า waitlist) / เหลือน้อย 3-2-1 /
        // ข้ามแถบสถานะการันตีออกเดินทาง — ทุกอย่าง self-guard และ dedupe ในตัว
        $this->seatNotifier->seatsIncreased(
            $scheduleId,
            $bookedBeforeBooking,
            $bookedAfterBooking,
            $booking->booking_ref,
        );

        return $booking;
    }

    /** payload หมุดที่ลูกค้าปักเองครบพอที่จะใช้งานได้หรือยัง (ต้องมีทั้งพิกัดและชื่อจุด) */
    private static function hasCustomPin(?array $customPickup): bool
    {
        return $customPickup !== null
            && isset($customPickup['lat'], $customPickup['lng'], $customPickup['label']);
    }

    /**
     * ยกเลิกการจองที่ค้างสถานะ pending เกินกำหนด (ลูกค้าไม่ชำระเงินต่อ) เพื่อคืนที่นั่งให้คนอื่นจองได้
     * เรียกเป็นระยะจาก ExpirePendingBookingsJob — คืนค่าจำนวนการจองที่ถูกยกเลิก
     */
    public function expireStalePendingBookings(): int
    {
        $threshold = now()->subMinutes(Booking::PENDING_TTL_MINUTES);

        // ยกเว้นรายการที่ส่งสลิปแล้วแต่กำลัง "รอตรวจสอบยอด" (slip_ocr_status ถูกตั้งค่า):
        // ลูกค้าจ่ายมาแล้ว รอแอดมินอนุมัติ ต้อง hold ที่นั่งไว้ ไม่ให้ timer ยกเลิกทิ้ง
        $candidateIds = Booking::where('status', 'pending')
            ->whereNull('slip_ocr_status')
            ->where('created_at', '<=', $threshold)
            ->pluck('id');

        $expiredBookings = [];
        // booked_seats ของแต่ละรอบ "ก่อน" คืนที่นั่งรายการแรก — ใช้เทียบตอนแจ้งเตือนที่ว่าง
        $bookedBefore = [];

        foreach ($candidateIds as $bookingId) {
            $expired = DB::transaction(function () use ($bookingId, $threshold, &$bookedBefore) {
                $booking = Booking::with('seats')->lockForUpdate()->find($bookingId);

                // ตรวจซ้ำใต้ lock — ลูกค้าอาจเพิ่งชำระเงินไประหว่างนี้ (สถานะเปลี่ยนเป็น confirmed)
                if (! $booking || $booking->status !== 'pending' || $booking->slip_ocr_status !== null || $booking->created_at->gt($threshold)) {
                    return null;
                }

                $booking->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => 'หมดเวลาชำระเงิน — ระบบยกเลิกอัตโนมัติเพื่อคืนที่นั่งภายใน '.Booking::PENDING_TTL_MINUTES.' นาที',
                    'cancelled_at' => now(),
                    'was_auto_expired' => true,
                ]);

                $schedule = $booking->schedule()->lockForUpdate()->first();
                if ($schedule) {
                    $bookedBefore[$schedule->id] ??= (int) $schedule->booked_seats;
                }

                // ปล่อย soft lock ที่นั่งและลบ booking seats แล้วปรับตัวนับที่นั่งให้ตรง
                foreach ($booking->seats as $seat) {
                    $this->seatLockService->forceUnlock($booking->schedule_id, $seat->seat_id);
                }
                $booking->seats()->delete();

                $schedule?->syncBookedSeats();

                return $booking;
            });

            if ($expired) {
                $expiredBookings[] = $expired;
            }
        }

        $scheduleIds = [];
        foreach ($expiredBookings as $expired) {
            $scheduleIds[$expired->schedule_id] = true;

            // แจ้งทางอีเมล (ไม่มีค่าส่ง) — งดส่ง SMS เพื่อประหยัดค่าส่งสำหรับการจองที่ถูกทิ้ง
            $this->mailService->sendBookingCancelledEmail($expired, $expired->cancellation_reason);

            SmartNotification::send(
                $expired->user_id,
                'booking_expired',
                'การจองหมดเวลา',
                "เลขการจอง {$expired->booking_ref} ถูกยกเลิกอัตโนมัติ เนื่องจากไม่ได้ชำระเงินภายใน ".Booking::PENDING_TTL_MINUTES.' นาที ที่นั่งถูกปล่อยคืนแล้ว',
                [
                    'booking_ref' => $expired->booking_ref,
                    'route' => 'booking',
                ],
            );
        }

        // ปล่อยที่นั่งคืน — เสนอให้คนใน waitlist ของรอบที่ได้ที่นั่งคืน
        // แล้วประกาศที่ว่างให้คนทั่วไป (เงียบเองถ้ายังมีคิวรออยู่)
        foreach (array_keys($scheduleIds) as $scheduleId) {
            ProcessWaitlistJob::dispatch($scheduleId);
            $this->seatNotifier->seatsFreed(
                $scheduleId,
                $bookedBefore[$scheduleId] ?? null,
                (int) (TripSchedule::find($scheduleId)?->booked_seats ?? 0),
            );
        }

        return count($expiredBookings);
    }

    /**
     * นัดกลับลูกค้าที่จองค้าง (abandoned booking win-back): การจองที่ถูกยกเลิก
     * อัตโนมัติเพราะไม่ชำระเงิน ถ้ารอบยังเปิดจองและลูกค้ายังไม่กลับมาจองใหม่
     * จะส่งการแจ้งเตือนชวนกลับมาจองให้เสร็จ — ส่งครั้งเดียวต่อการจอง
     *
     * เรียกเป็นระยะจาก AbandonedBookingWinbackJob — คืนจำนวนที่ส่งสำเร็จ
     */
    public function sendAbandonedWinbacks(): int
    {
        // รอสัก 2 ชม. ก่อนตาม (กันรบกวนคนที่แค่สะดุดชั่วครู่) และตามภายใน 48 ชม.
        // ที่ความสนใจยังสด — เลยกว่านั้นถือว่าเย็นเกินไป
        $candidates = Booking::query()
            ->where('was_auto_expired', true)
            ->whereNull('winback_sent_at')
            ->where('cancelled_at', '<=', now()->subHours(2))
            ->where('cancelled_at', '>=', now()->subHours(48))
            ->with(['schedule.trip', 'user'])
            ->get();

        $sent = 0;

        foreach ($candidates as $booking) {
            $schedule = $booking->schedule;
            $trip = $schedule?->trip;

            // ไม่มีรอบ/ทริปแล้ว หรือลูกค้าหาย — ปิดไม่ให้ตามซ้ำ
            if (! $schedule || ! $trip || ! $booking->user) {
                $booking->update(['winback_sent_at' => now()]);

                continue;
            }

            // ลูกค้ากลับมาจองรอบนี้แล้ว (pending/confirmed) — ไม่ต้องตาม
            $alreadyRebooked = Booking::where('user_id', $booking->user_id)
                ->where('schedule_id', $booking->schedule_id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();

            // รอบต้องยังเปิดจองได้จริง: ไม่ถูกยกเลิก ยังไม่ออกเดินทาง และมีที่ว่าง
            $schedule->syncBookedSeats();
            $bookable = $schedule->status !== 'cancelled'
                && $schedule->effectiveDepartsAt()
                && $schedule->effectiveDepartsAt()->isFuture()
                && $schedule->available_seats > 0;

            if ($alreadyRebooked || ! $bookable) {
                $booking->update(['winback_sent_at' => now()]);

                continue;
            }

            SmartNotification::send(
                $booking->user_id,
                'booking_winback',
                'ที่นั่งยังว่างอยู่นะ 🎒',
                "ทริป \"{$trip->title}\" ที่คุณเลือกไว้ยังเปิดจองอยู่ — กลับมาจองให้เสร็จก่อนที่นั่งจะเต็ม",
                [
                    'trip_slug' => $trip->slug,
                    'schedule_id' => (string) $schedule->id,
                    'route' => 'trip',
                ],
            );

            $booking->update(['winback_sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    public function confirmBooking(Booking $booking, string $paymentMethod, string $paymentRef, ?float $amount = null): Booking
    {
        return DB::transaction(function () use ($booking, $paymentMethod, $paymentRef, $amount) {
            $booking->update([
                'status' => 'confirmed',
                'paid_amount' => $amount ?? $booking->total_amount,
                'payment_method' => $paymentMethod,
                'payment_ref' => $paymentRef,
                'paid_at' => now(),
            ]);

            // Release seat locks
            if ($booking->seats->isNotEmpty()) {
                foreach ($booking->seats as $seat) {
                    $this->seatLockService->forceUnlock($booking->schedule_id, $seat->seat_id);
                }
            }

            // แต้มและจำนวนทริปสะสมถูกบันทึกโดย BookingObserver ตอน status เปลี่ยน
            // เป็น confirmed ด้านบนแล้ว — ครอบคลุมทางที่แอดมินยืนยันใบจองเองด้วย

            // Reward the referrer + friend on the friend's first paid booking.
            $this->referralService->qualifyFromBooking($booking);

            return $booking->fresh(['passengers', 'seats', 'schedule.trip']);
        });
    }

    public function cancelBooking(Booking $booking, ?string $reason = null): Booking
    {
        $bookedBefore = null;

        $cancelled = DB::transaction(function () use ($booking, $reason, &$bookedBefore) {
            $booking->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            // Keep the cached counter aligned with active, non-join-trip bookings.
            $schedule = $booking->schedule()->lockForUpdate()->first();
            $bookedBefore = $schedule ? (int) $schedule->booked_seats : null;
            $schedule?->syncBookedSeats();

            // Release seat locks & delete booking seats
            foreach ($booking->seats as $seat) {
                $this->seatLockService->forceUnlock($booking->schedule_id, $seat->seat_id);
            }
            $booking->seats()->delete();

            return $booking->fresh(['passengers', 'schedule.trip']);
        });

        // Send cancellation email outside of DB transaction
        $this->mailService->sendBookingCancelledEmail($cancelled, $reason);
        $this->smsService->sendBookingCancelled($cancelled, $reason);
        SmartNotification::send(
            $cancelled->user_id,
            'booking_cancelled',
            'การจองถูกยกเลิก',
            "เลขการจอง {$cancelled->booking_ref} ถูกยกเลิกแล้ว",
            [
                'booking_ref' => $cancelled->booking_ref,
                'route' => 'booking',
            ],
        );

        // Notify next users in the waitlist now that seats are freed
        ProcessWaitlistJob::dispatch($cancelled->schedule_id);

        // ไม่มีคิวรอ = ที่นั่งที่คืนมากลับสู่สาธารณะ ประกาศให้คนที่พลาดรอบนี้รู้
        $this->seatNotifier->seatsFreed(
            $cancelled->schedule_id,
            $bookedBefore,
            (int) ($cancelled->schedule?->booked_seats ?? 0),
        );

        return $cancelled;
    }

    /**
     * ลูกค้าเปลี่ยนวันเดินทาง (ย้ายไปอีกรอบของทริปเดียวกัน) — คงราคาเดิม เลือกที่นั่งใหม่
     * ใช้ได้กับสถานะ pending/confirmed และก่อนเดินทาง 1 วันเท่านั้น
     *
     * @param  string[]  $seatIds  ที่นั่งใหม่บนรอบปลายทาง (จำเป็นถ้าการจองเดิมเป็นแบบเลือกที่นั่ง)
     */
    public function rescheduleBooking(Booking $booking, int $targetScheduleId, array $seatIds = [], ?int $pickupPointId = null): Booking
    {
        $originalScheduleId = $booking->schedule_id;

        $rescheduled = DB::transaction(function () use ($booking, $targetScheduleId, $seatIds, $pickupPointId) {
            $booking->loadMissing(['schedule.trip', 'passengers', 'seats']);

            if ($booking->rescheduled_at !== null) {
                throw new \Exception('การจองนี้เปลี่ยนวันเดินทางได้เพียงครั้งเดียว (ใช้สิทธิ์ไปแล้ว)');
            }

            if (! $booking->canBeRescheduled()) {
                throw new \Exception('เปลี่ยนวันเดินทางได้ก่อนเดินทางอย่างน้อย '.Booking::RESCHEDULE_LEAD_DAYS.' วันเท่านั้น');
            }

            $source = TripSchedule::lockForUpdate()->findOrFail($booking->schedule_id);
            $target = TripSchedule::lockForUpdate()->findOrFail($targetScheduleId);

            if ($target->id === $source->id) {
                throw new \Exception('กรุณาเลือกรอบเดินทางอื่น');
            }

            if ((int) $target->trip_id !== (int) $source->trip_id) {
                throw new \Exception('เปลี่ยนได้เฉพาะรอบเดินทางของทริปเดียวกัน');
            }

            if ($target->status !== 'open') {
                throw new \Exception('รอบเดินทางปลายทางไม่เปิดรับจอง');
            }

            if ($target->departure_date->lt(now()->startOfDay())) {
                throw new \Exception('ไม่สามารถเปลี่ยนไปยังรอบเดินทางที่ผ่านมาแล้ว');
            }

            $source->syncBookedSeats();
            $target->syncBookedSeats();

            $passengerCount = $booking->passengers->count();
            $usesSeats = ! $booking->is_join_trip && $booking->seats->isNotEmpty();

            if ($booking->is_join_trip && ! $target->join_trip_enabled) {
                throw new \Exception('รอบเดินทางปลายทางไม่เปิดให้จองแบบ Join Trip');
            }

            // ตรวจที่นั่งสำหรับการจองแบบเลือกที่นั่ง
            $newSeatIds = [];
            if ($usesSeats) {
                $newSeatIds = collect($seatIds)
                    ->map(fn ($id) => trim((string) $id))
                    ->filter()
                    ->values();

                if ($newSeatIds->count() !== $passengerCount) {
                    throw new \Exception("กรุณาเลือกที่นั่งใหม่ให้ครบ {$passengerCount} ที่นั่ง");
                }

                if ($newSeatIds->duplicates()->isNotEmpty()) {
                    throw new \Exception('เลือกที่นั่งซ้ำกัน กรุณาเลือกใหม่');
                }

                if ($target->available_seats < $passengerCount) {
                    throw new \Exception("ที่นั่งในรอบปลายทางไม่เพียงพอ (ต้องการ {$passengerCount}, ว่าง {$target->available_seats})");
                }

                $occupied = BookingSeat::where('schedule_id', $target->id)
                    ->whereIn('seat_id', $newSeatIds->all())
                    ->pluck('seat_id')
                    ->unique()
                    ->values();

                if ($occupied->isNotEmpty()) {
                    throw new \Exception('ที่นั่ง '.$occupied->join(', ').' ในรอบปลายทางถูกจองแล้ว กรุณาเลือกที่นั่งอื่น');
                }
            } elseif (! $booking->is_join_trip && $target->available_seats < $passengerCount) {
                throw new \Exception("ที่นั่งในรอบปลายทางไม่เพียงพอ (ต้องการ {$passengerCount}, ว่าง {$target->available_seats})");
            }

            // กำหนดจุดรับใหม่ (ถ้าระบุ) — ต้องอยู่ในรอบปลายทาง; คงราคาเดิมเสมอ
            $pickupPoint = null;
            if ($pickupPointId) {
                $pickupPoint = SchedulePickupPoint::where('id', $pickupPointId)
                    ->where('schedule_id', $target->id)
                    ->first();

                if (! $pickupPoint) {
                    throw new \Exception('จุดรับที่เลือกไม่อยู่ในรอบเดินทางปลายทาง');
                }
            }

            // ย้ายที่นั่งไปรอบปลายทาง
            if ($usesSeats) {
                $passengerNames = $booking->passengers->pluck('name')->values();
                $booking->seats()->delete();

                foreach ($newSeatIds as $index => $seatId) {
                    $this->seatLockService->forceUnlock($target->id, $seatId);
                    BookingSeat::create([
                        'booking_id' => $booking->id,
                        'schedule_id' => $target->id,
                        'seat_id' => $seatId,
                        'passenger_name' => $passengerNames->get($index),
                    ]);
                }
            }

            // จุดรับผูกกับรอบ — จุดรับรายคนที่ค้างจากรอบเดิมต้องย้ายตาม (จับคู่จากชื่อจุด)
            // ไม่งั้นสตาฟจะเห็นจุดรับ/เวลารับของรอบเดิมตอนเช็คอิน
            $this->remapPassengerPickupPoints($booking, $this->pickupPointMap($source, $target));

            $booking->update([
                'schedule_id' => $target->id,
                'pickup_point_id' => $pickupPoint?->id,
                'pickup_region' => $pickupPoint?->region,
                'rescheduled_at' => now(),
            ]);

            $source->syncBookedSeats();
            $target->syncBookedSeats();

            return $booking->fresh(['passengers', 'seats', 'schedule.trip', 'pickupPoint']);
        });

        // แจ้งเตือนนอก transaction
        SmartNotification::send(
            $rescheduled->user_id,
            'booking_rescheduled',
            'เปลี่ยนวันเดินทางสำเร็จ',
            "การจอง {$rescheduled->booking_ref} ย้ายไปวันที่ ".$rescheduled->schedule->departureLabelShort().' แล้ว',
            [
                'booking_ref' => $rescheduled->booking_ref,
                'route' => 'booking',
            ],
        );

        // ปล่อยที่นั่งคืนรอบเดิม — แจ้ง waitlist
        ProcessWaitlistJob::dispatch($originalScheduleId);

        return $rescheduled;
    }

    /**
     * ลูกค้าเปลี่ยนจุดรับ — คงราคาเดิม ใช้ได้ก่อนเดินทาง 1 วัน
     */
    public function changePickupPoint(Booking $booking, int $pickupPointId): Booking
    {
        return DB::transaction(function () use ($booking, $pickupPointId) {
            $booking->loadMissing('schedule');

            if (! $booking->canBeModified()) {
                throw new \Exception('การจองนี้ไม่สามารถเปลี่ยนจุดรับได้ (เปลี่ยนได้ถึงก่อนเดินทาง 1 วัน)');
            }

            $pickupPoint = SchedulePickupPoint::where('id', $pickupPointId)
                ->where('schedule_id', $booking->schedule_id)
                ->first();

            if (! $pickupPoint) {
                throw new \Exception('จุดรับที่เลือกไม่อยู่ในรอบเดินทางนี้');
            }

            $previousPointId = $booking->pickup_point_id ? (int) $booking->pickup_point_id : null;

            $booking->update([
                'pickup_point_id' => $pickupPoint->id,
                'pickup_region' => $pickupPoint->region,
            ]);

            // จุดรับรายคนมาก่อนจุดระดับการจองในหน้าสตาฟ/คนขับ — คนที่ยังยืนจุดเดิม
            // ต้องย้ายตาม ส่วนคนที่เลือกจุดของตัวเองไว้ต่างหากคงไว้ตามเดิม
            if ($previousPointId !== (int) $pickupPoint->id) {
                $booking->passengers()
                    ->where(function ($query) use ($previousPointId) {
                        $query->whereNull('pickup_point_id');
                        if ($previousPointId) {
                            $query->orWhere('pickup_point_id', $previousPointId);
                        }
                    })
                    ->update(['pickup_point_id' => $pickupPoint->id]);
            }

            return $booking->fresh(['passengers', 'seats', 'schedule.trip', 'pickupPoint']);
        });
    }

    public function calculateRefundPercent(Booking $booking): int
    {
        $schedule = $booking->schedule;
        // นับถอยหลังจากวันออกรถจริง (อาจเป็นคืนก่อนวันทริป)
        $daysUntilDeparture = now()->diffInDays($schedule->effectiveDepartureDate(), false);

        if ($daysUntilDeparture >= 7) {
            return 80;
        }
        if ($daysUntilDeparture >= 3) {
            return 50;
        }

        return 0;
    }

    /**
     * คำนวณยอดคืนเงินจริงตาม payment_type และวันที่ยกเลิก
     * - full/installment: คำนวณ % จาก paid_amount
     * - deposit: คืนแค่ balance ถ้ายังไม่ได้ชำระ (มัดจำไม่คืน)
     */
    public function calculateRefundAmount(Booking $booking): array
    {
        $paidAmount = (float) $booking->paid_amount;
        $paymentType = $booking->payment_type ?? 'full';

        if ($paymentType === 'deposit') {
            // มัดจำไม่คืนทุกกรณี — คืนแค่ส่วนที่เหลือ ถ้าชำระแล้ว
            $balancePaid = (float) ($booking->balance_amount ?? 0);
            $refundable = $booking->balance_paid_at ? $balancePaid : 0.0;

            return [
                'refund_percent' => $refundable > 0 ? 100 : 0,
                'refund_amount' => $refundable,
                'deposit_amount' => (float) ($booking->deposit_amount ?? 0),
                'policy_note' => 'มัดจำไม่คืนทุกกรณี คืนเฉพาะยอดส่วนที่เหลือที่ชำระแล้ว',
            ];
        }

        $percent = $this->calculateRefundPercent($booking);
        $refundAmount = round($paidAmount * $percent / 100, 2);

        return [
            'refund_percent' => $percent,
            'refund_amount' => $refundAmount,
            'paid_amount' => $paidAmount,
            'policy_note' => match (true) {
                $percent === 80 => 'ยกเลิกก่อนเดินทาง 7+ วัน คืน 80%',
                $percent === 50 => 'ยกเลิกก่อนเดินทาง 3–6 วัน คืน 50%',
                default => 'ยกเลิกก่อนเดินทางน้อยกว่า 3 วัน ไม่คืนเงิน',
            },
        ];
    }

    /**
     * Admin: บันทึกการคืนเงิน — อัปเดต refund fields และเปลี่ยนสถานะเป็น 'refunded'
     */
    public function processRefund(Booking $booking, float $refundAmount, ?string $note = null, ?string $slipPath = null): Booking
    {
        $refunded = DB::transaction(function () use ($booking, $refundAmount, $note, $slipPath) {
            $booking->loadMissing('seats');

            $booking->update([
                'status' => 'refunded',
                'refund_status' => 'refunded',
                'refund_amount' => $refundAmount,
                'refunded_at' => now(),
                'refund_slip_path' => $slipPath ?? $booking->refund_slip_path,
                'cancellation_reason' => $note ?? $booking->cancellation_reason,
                'cancelled_at' => $booking->cancelled_at ?? now(),
            ]);

            // ปล่อยที่นั่งคืน — booking ที่ refund แล้วต้องไม่ถือที่นั่งไว้ (เหมือน cancelBooking)
            // มิฉะนั้นแถว booking_seats จะค้างและไปชน unique constraint ตอนมีคนจองที่นั่งเดิมซ้ำ
            foreach ($booking->seats as $seat) {
                $this->seatLockService->forceUnlock($booking->schedule_id, $seat->seat_id);
            }
            $booking->seats()->delete();

            // Sync seats back
            $schedule = $booking->schedule()->lockForUpdate()->first();
            $schedule?->syncBookedSeats();

            return $booking->fresh(['passengers', 'schedule.trip']);
        });

        // Notify next users in the waitlist now that seats are freed
        ProcessWaitlistJob::dispatch($booking->schedule_id);

        return $refunded;
    }
}
