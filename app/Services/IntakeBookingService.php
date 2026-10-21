<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CustomerIntake;
use App\Models\CustomerIntakePerson;
use App\Models\TripSchedule;
use App\Support\TermsConsent;
use App\Support\TripRentalItems;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ลูกค้ากดจองเองจากลิงก์กลุ่ม (/g/{token}) แล้วจ่ายต่อได้ทันที
 *
 * เดิมลิงก์นี้เก็บได้แค่ข้อมูล แล้วรอทีมงานดึงไปเปิดใบจองและส่ง QR กลับไปในแชท
 * ช่วงที่รอนั้นที่นั่งไม่ได้ถูกกันไว้ ({@see IntakeSeatService}) คนที่กดจองในแอป
 * แล้วจ่ายก่อนจึงได้ที่นั่งไป ลูกค้าที่กรอกครบแล้วกลับไม่ได้ไป
 *
 * ตอนนี้กลุ่มที่พร้อมแล้วกดจองเองได้ — ใบจองเกิดผ่าน BookingService ตัวเดียวกับ
 * แอป ได้หน้าต่างชำระเงิน {@see Booking::PENDING_TTL_MINUTES} นาทีเท่ากัน ไม่จ่าย
 * ระบบคืนที่นั่งเอง แล้ว BookingObserver คืนกลุ่มกลับมาให้กดใหม่หรือให้ทีมงานโทรตาม
 *
 * ลิงก์กลุ่มอยู่ในแชท ใครก็กดได้ จึงมีเพดาน {@see self::MAX_SELF_BOOKINGS} ครั้ง
 * ต่อกลุ่ม กันคนที่กดจองเล่นแล้วไม่จ่าย ไม่ให้กันที่นั่งของคนอื่นไว้ได้ไม่รู้จบ
 */
class IntakeBookingService
{
    /** กดจองเองได้กี่ครั้งต่อกลุ่ม — เกินนี้ต้องให้ทีมงานเปิดให้ */
    public const MAX_SELF_BOOKINGS = 3;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly CustomerAccountService $accounts,
        private readonly IntakeSeatService $seats,
        private readonly SeatLockService $locks,
        private readonly AccountClaimService $claims,
        private readonly BookingDocumentService $documents,
    ) {}

    /**
     * ทำไมกลุ่มนี้ยังกดจองเองไม่ได้ — null = จองได้
     *
     * คำตอบเป็นประโยคที่ลูกค้าอ่านแล้วรู้ว่าต้องทำอะไรต่อ ไม่ใช่รหัสข้อผิดพลาด
     */
    public function blockReason(CustomerIntake $intake): ?string
    {
        if (! $intake->acceptsSubmissions()) {
            return 'กลุ่มนี้มีการจองแล้ว';
        }

        $schedule = $intake->schedule;
        if (! $schedule) {
            return 'กลุ่มนี้ยังไม่ได้เลือกรอบเดินทาง ทีมงานจะติดต่อกลับเพื่อยืนยันรอบให้';
        }

        // รอบเหมาราคาตกลงกันเป็นรายกลุ่ม ทีมงานเป็นคนเปิดใบจองให้เสมอ
        if ($schedule->is_charter) {
            return 'รอบนี้เป็นรอบเหมา ทีมงานจะเปิดการจองให้';
        }

        if (! $schedule->acceptsBookingType($intake->booking_type)) {
            return 'รอบนี้ปิดรับแล้ว ทีมงานจะติดต่อกลับเรื่องรอบอื่นหรือคิวรอที่นั่ง';
        }

        if (! $schedule->isBookableBy(null)) {
            $opensAt = $schedule->bookingOpensAtFor(null)?->timezone('Asia/Bangkok');

            return 'รอบนี้ยังไม่เปิดจอง'.($opensAt ? ' เปิดให้จองวันที่ '.$opensAt->format('j/n/').($opensAt->year + 543).' เวลา '.$opensAt->format('H:i').' น.' : '');
        }

        $people = $intake->people()->get();
        if ($people->isEmpty()) {
            return 'ยังไม่มีข้อมูลผู้เดินทาง';
        }

        if ((int) $intake->self_booking_attempts >= self::MAX_SELF_BOOKINGS) {
            return 'กลุ่มนี้กดจองไปแล้วหลายครั้งแต่ยังไม่ได้ชำระเงิน กรุณาทักทีมงานเพื่อจองต่อ';
        }

        return $this->seatProblem($intake, $schedule, $people);
    }

    /**
     * ยอดโดยประมาณก่อนกดจอง — ให้ลูกค้ารู้ว่ากำลังจะจ่ายราว ๆ เท่าไร
     *
     * ยอดจริงคิดใหม่ทั้งหมดใน BookingService ตอนกดจอง (แคมเปญ ส่วนต่างรถ ฯลฯ) หน้านี้
     * จึงเรียกมันว่า "ประมาณ" เสมอ และหน้าชำระเงินอ่านยอดจาก PaymentQuote เท่านั้น
     *
     * @return array{people: int, total: float, deposit_enabled: bool}|null
     */
    public function estimate(CustomerIntake $intake): ?array
    {
        $schedule = $intake->schedule;
        if (! $schedule) {
            return null;
        }

        $people = $intake->people()->get();
        if ($people->isEmpty()) {
            return null;
        }

        $points = $schedule->pickupPoints()->get()->keyBy('id');

        $fares = $people->sum(function (CustomerIntakePerson $person) use ($intake, $schedule, $points) {
            if ($intake->isJoinTrip()) {
                return (float) ($schedule->effective_join_trip_price ?? $schedule->effective_price);
            }

            $point = $points->get($person->pickup_point_id);
            if (! $point) {
                return (float) $schedule->effective_price;
            }

            $point->setRelation('schedule', $schedule);

            return (float) $point->effective_price;
        });

        // ราคาเช่าตามแคตตาล็อกตอนนี้ ไม่ใช่ราคาที่แช่ไว้ตอนกรอก — ตรงกับที่ใบจองจะคิดจริง
        $catalog = $schedule->trip?->rentalItems() ?? [];
        $rentals = array_sum(array_column(
            TripRentalItems::snapshotSelection($this->rentalQuantities($people), $catalog),
            'total_price',
        ));

        return [
            'people' => $people->count(),
            'total' => round($fares + $rentals, 2),
            'deposit_enabled' => (bool) $schedule->deposit_enabled,
        ];
    }

    /**
     * เปิดใบจองจากข้อมูลทั้งกลุ่ม — สถานะ pending รอชำระเงินเหมือนจองในแอป
     *
     * @throws \Exception ข้อความภาษาไทยที่แสดงให้ลูกค้าได้ตรง ๆ
     */
    public function book(CustomerIntake $intake, ?TermsConsent $consent): Booking
    {
        // กดปุ่มสองครั้ง หรือเพื่อนสองคนกดพร้อมกันจากแชทกลุ่ม ต้องได้ใบจองใบเดียว
        $lock = Cache::lock('intake-self-book:'.$intake->id, 30);

        if (! $lock->get()) {
            throw new \Exception('มีคนในกลุ่มกำลังกดจองอยู่ กรุณารอสักครู่แล้วโหลดหน้านี้ใหม่');
        }

        try {
            $intake->refresh();
            $intake->load('schedule.trip');

            if ($reason = $this->blockReason($intake)) {
                throw new \Exception($reason);
            }

            return $this->createBooking($intake, $consent);
        } finally {
            $lock->release();
        }
    }

    /**
     * จ่ายแล้ว — ส่งลิงก์เปิดใช้บัญชีเข้าเบอร์ลูกค้า ถ้าใบจองไปอยู่ในบัญชีเงา
     *
     * ส่งหลังจ่าย ไม่ใช่ตอนกดจอง: คนที่กดจองแล้วไม่จ่ายไม่ควรได้ SMS ชวนเปิดบัญชี
     * ของใบจองที่อีกสิบนาทีจะหายไป เรียกซ้ำได้ — SmsService กันซ้ำต่อใบจองอยู่แล้ว
     */
    public function afterPaid(Booking $booking): void
    {
        if ($booking->status !== 'confirmed') {
            return;
        }

        try {
            $this->claims->sendClaimLink($booking, selfBooked: true);
        } catch (\Throwable $e) {
            // เงินเข้าแล้ว ที่นั่งยืนยันแล้ว SMS ส่งไม่ออกไม่ใช่เหตุให้หน้าพัง
            report($e);
        }
    }

    /**
     * เส้นตายชำระเงินของใบจองนี้ — กติกาเดียวกับ BookingResource.expires_at
     *
     * null = ไม่มีเส้นตายแล้ว (ส่งสลิปแล้วรอตรวจ หรือไม่ได้ค้างชำระ)
     */
    public function paymentDeadline(Booking $booking): ?Carbon
    {
        if ($booking->status !== 'pending' || $booking->slip_ocr_status !== null) {
            return null;
        }

        return $booking->hold_until
            ? $booking->hold_until->copy()
            : $booking->created_at?->copy()->addMinutes(Booking::PENDING_TTL_MINUTES);
    }

    private function createBooking(CustomerIntake $intake, ?TermsConsent $consent): Booking
    {
        $schedule = $intake->schedule;
        $people = $intake->people()->get();
        $isJoin = $intake->isJoinTrip();
        $lead = $people->firstWhere('is_lead', true) ?? $people->first();

        $user = $this->accounts->findOrCreate(
            $lead->name,
            (string) ($lead->phone ?: $intake->contact_phone),
            $lead->email ?: $intake->contact_email,
        );

        $map = $this->seats->mapFor($schedule, $isJoin, $intake);
        $seatIds = $map ? $people->pluck('seat_id')->map(fn ($id) => (string) $id)->all() : [];
        $optionId = $map ? ((int) $map['vehicle_option_id'] ?: null) : null;

        // ที่นั่งที่ลูกค้าคนอื่นกำลังจองอยู่ในแอป (ล็อกใน Redis) — คนนั้นมาก่อน
        // BookingService ข้ามการตรวจล็อกให้เรา (ฟอร์มนี้ไม่เคยล็อก) จึงต้องถามเองตรงนี้
        if ($seatIds !== []) {
            $busy = collect($this->locks->getSeatStatus($schedule->id, $seatIds, $user->id, (int) $optionId))
                ->filter(fn (array $seat) => $seat['status'] === 'locked' && ! ($seat['locked_by_current_user'] ?? false))
                ->keys();

            if ($busy->isNotEmpty()) {
                throw new \Exception('ที่นั่ง '.$busy->join(', ').' มีคนกำลังจองอยู่ กรุณาให้เจ้าของที่นั่งกรอกข้อมูลอีกครั้งด้วยเบอร์เดิมแล้วเลือกที่นั่งใหม่');
            }
        }

        $passengers = $people->map(function (CustomerIntakePerson $person) use ($isJoin) {
            $payload = $person->toPassengerPayload();
            $payload['pickup_point_id'] = $isJoin ? null : $person->pickup_point_id;

            return $payload;
        })->values()->all();

        // จุดขึ้นรถของหัวใบจอง — ใบเสร็จ/อีเมลอ่านทีละใบ ใช้จุดของคนเปิดกลุ่มก่อน
        $pickupPointId = $isJoin
            ? null
            : ($lead->pickup_point_id ?: $people->pluck('pickup_point_id')->filter()->first());

        $booking = $this->bookings->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $passengers,
            seatIds: $seatIds,
            pickupPointId: $pickupPointId ? (int) $pickupPointId : null,
            isGroup: count($passengers) > 1,
            groupNotes: $intake->note,
            isJoinTrip: $isJoin,
            selectedRentals: $this->rentalIndexes($schedule, $people),
            // ฟอร์มนี้ไม่เคยล็อกที่นั่งใน Redis โดยตั้งใจ — ตัวกันจองซ้ำจริงคือ
            // booking_seats ใต้ lockForUpdate ของรอบใน BookingService
            verifySeatLocks: false,
            vehicleOptionId: $optionId,
            termsConsent: $consent,
        );

        // รูปบัตรประชาชน (Border Pass) ที่แนบมากับฟอร์ม — ใบจองต้องเห็นเหมือนแนบเอง
        $this->documents->adoptIntakeIdFiles($booking, $people);

        $intake->forceFill([
            'status' => 'booked',
            'booking_id' => $booking->id,
            'converted_at' => now(),
            'self_booked_at' => now(),
            'self_booking_attempts' => (int) $intake->self_booking_attempts + 1,
            'last_activity_at' => now(),
        ])->save();

        return $booking;
    }

    /**
     * ที่นั่งของกลุ่มยังใช้ได้ไหม — null = พร้อม
     *
     * รอบที่มีผังที่นั่ง ทุกคนต้องมีที่นั่งของตัวเอง (ครึ่งกลุ่มมีครึ่งไม่มีจับคู่ชื่อ
     * กับที่นั่งไม่ได้) และที่นั่งนั้นต้องยังไม่ถูกคนอื่นจองหรือล็อกไประหว่างที่รอ
     *
     * @param  Collection<int, CustomerIntakePerson>  $people
     */
    private function seatProblem(CustomerIntake $intake, TripSchedule $schedule, Collection $people): ?string
    {
        $map = $this->seats->mapFor($schedule, $intake->isJoinTrip(), $intake);
        if (! $map) {
            return null;
        }

        $missing = $people->filter(fn (CustomerIntakePerson $person) => blank($person->seat_id));
        if ($missing->isNotEmpty()) {
            return 'ยังไม่ได้เลือกที่นั่ง: '.$missing->map->publicLabel()->join(', ')
                .' — ให้กรอกข้อมูลอีกครั้งด้วยเบอร์เดิมแล้วเลือกที่นั่ง';
        }

        $states = collect($map['seats'])->keyBy('id');
        $taken = $people->filter(function (CustomerIntakePerson $person) use ($states) {
            $state = $states->get($person->seat_id)['state'] ?? null;

            // ที่นั่งที่หายจากผัง (เปลี่ยนรถ) ก็ใช้ไม่ได้เหมือนถูกจองไปแล้ว
            return $state === null || in_array($state, ['booked', 'held'], true);
        });

        if ($taken->isNotEmpty()) {
            return 'ที่นั่งของ '.$taken->map->publicLabel()->join(', ')
                .' ถูกจองไปแล้ว — ให้กรอกข้อมูลอีกครั้งด้วยเบอร์เดิมแล้วเลือกที่นั่งใหม่';
        }

        $duplicate = $people->pluck('seat_id')->duplicates()->unique();
        if ($duplicate->isNotEmpty()) {
            return 'มีเพื่อนในกลุ่มเลือกที่นั่งซ้ำกัน ('.$duplicate->join(', ').') — ให้คนใดคนหนึ่งกรอกอีกครั้งแล้วเลือกที่นั่งใหม่';
        }

        return null;
    }

    /**
     * อุปกรณ์เช่าของทั้งกลุ่มเป็น key → จำนวน
     *
     * @param  Collection<int, CustomerIntakePerson>  $people
     * @return array<string, int>
     */
    private function rentalQuantities(Collection $people): array
    {
        $quantities = [];

        foreach (TripRentalItems::mergeSelections($people->map->rentals()) as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $quantities[$key] = ($quantities[$key] ?? 0) + (int) ($row['quantity'] ?? 0);
            }
        }

        return $quantities;
    }

    /**
     * BookingService รับอุปกรณ์เช่าเป็นลำดับในแคตตาล็อก (แบบเดียวกับแอป) ไม่ใช่ key
     * ของที่ถูกถอดออกจากทริประหว่างรอถูกข้ามไป ไม่ทำให้ทั้งการจองล้ม
     *
     * @param  Collection<int, CustomerIntakePerson>  $people
     * @return array<int, array{index: int, quantity: int}>
     */
    private function rentalIndexes(TripSchedule $schedule, Collection $people): array
    {
        $indexByKey = collect($schedule->trip?->rentalItems() ?? [])
            ->values()
            ->mapWithKeys(fn (array $item, int $index) => [(string) ($item['key'] ?? '') => $index]);

        $rows = [];
        foreach ($this->rentalQuantities($people) as $key => $quantity) {
            if ($quantity > 0 && $indexByKey->has($key)) {
                $rows[] = ['index' => $indexByKey->get($key), 'quantity' => $quantity];
            }
        }

        return $rows;
    }
}
