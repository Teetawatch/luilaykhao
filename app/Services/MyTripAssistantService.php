<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\ClaudeJson;
use App\Support\ThaiDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * ผู้ช่วยส่วนตัว — ตอบคำถามเกี่ยวกับ "การจองของฉัน" ("พรุ่งนี้รถออกกี่โมง",
 * "ยอดคงเหลือเท่าไหร่ จ่ายวันไหน", "ต้องเตรียมอะไรบ้าง")
 *
 * ต่างจาก [TripConciergeService] ที่แนะนำทริปให้คนยังไม่จอง — ตัวนี้รู้ว่าคุณคือใคร
 * จองรอบไหนไว้ แล้วตอบจากข้อมูลจริงของรอบนั้น
 *
 * หัวใจอยู่ที่ "ตอบจากบริบทที่ให้เท่านั้น": เราประกอบข้อมูลการจองของผู้ใช้คนนี้
 * ส่งไปกับ system prompt แล้วห้ามโมเดลเดาสิ่งที่ไม่มีในนั้น เรื่องที่ระบบยังไม่รู้
 * (เช่นยังไม่ assign รถ) ส่ง pending_note ไปด้วย ให้ตอบว่า "ทีมงานจะแจ้งให้"
 * แทนที่จะแต่งทะเบียนรถขึ้นมาเอง
 */
class MyTripAssistantService
{
    /** จำนวนข้อความย้อนหลังที่ส่งกลับไปเป็นบริบท (นับทั้งฝั่งผู้ใช้และผู้ช่วย). */
    private const MAX_HISTORY = 10;

    /** จำนวนการจองที่ส่งเข้าบริบท — พอให้ตอบได้โดยไม่เปลืองโทเคน. */
    private const MAX_BOOKINGS = 5;

    /** ทริปที่เพิ่งจบยังถูกถามถึงอยู่ (รูป/รีวิว/ของลืม) จึงยังส่งเข้าบริบท. */
    private const RECENT_PAST_DAYS = 21;

    /**
     * ปุ่มลัดที่แอปรู้จัก — จำกัดเป็น enum เพื่อให้ฝั่งแอปแมปเป็นหน้าจอได้แน่นอน
     * ไม่ต้องเดาจากข้อความ
     */
    public const ACTIONS = [
        'booking_detail' => 'ดูใบจอง',
        'payment' => 'ชำระเงิน',
        'pickup' => 'จุดรับ',
        'itinerary' => 'กำหนดการ',
        'checklist' => 'เช็กของ',
        'weather' => 'สภาพอากาศ',
        'trip_day' => 'วันเดินทาง',
        'chat' => 'ห้องแชท',
        'support' => 'คุยกับทีมงาน',
    ];

    public function __construct(private TripFactsService $facts) {}

    /**
     * ตอบคำถามหนึ่งข้อ
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, actions: array<int, array<string, mixed>>}
     *
     * @throws \Exception เมื่อยังไม่ได้ตั้งค่า API key หรือเรียกโมเดลไม่สำเร็จ
     */
    public function ask(User $user, string $question, array $history = []): array
    {
        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            throw new \Exception('ระบบผู้ช่วยยังไม่พร้อมใช้งาน กรุณาติดต่อทีมงาน');
        }

        $bookings = $this->bookings($user);

        $answer = ClaudeJson::ask(
            apiKey: $apiKey,
            model: config('services.anthropic.assistant_model'),
            systemPrompt: $this->systemPrompt($user, $bookings),
            messages: $this->messages($question, $history),
            schema: $this->schema(),
            effort: 'low',
            // เผื่อที่ให้ thinking ด้วย เพราะรุ่นใหม่คิดก่อนตอบเป็นค่าเริ่มต้น
            // ถ้าให้น้อยไปคำตอบจะถูกตัดกลางคัน
            maxTokens: 4096,
            context: 'MyTripAssistantService',
        );

        // กันโมเดลอ้างปุ่มหรือเลขที่จองที่ไม่มีจริง — เหลือเฉพาะที่ตรงกับข้อมูลจริง
        $refs = collect($bookings)->pluck('booking_ref')->filter()->all();

        $actions = collect($answer['actions'] ?? [])
            ->filter(fn ($action) => isset(self::ACTIONS[$action['type'] ?? '']))
            ->map(fn ($action) => [
                'type' => $action['type'],
                'label' => trim((string) ($action['label'] ?? '')) ?: self::ACTIONS[$action['type']],
                'booking_ref' => in_array($action['booking_ref'] ?? null, $refs, true)
                    ? $action['booking_ref']
                    : null,
            ])
            ->unique(fn ($action) => $action['type'].'|'.$action['booking_ref'])
            ->take(3)
            ->values()
            ->all();

        return [
            'reply' => trim($answer['reply'] ?? ''),
            'actions' => $actions,
        ];
    }

    /**
     * การจองที่ยังเกี่ยวข้องกับผู้ใช้คนนี้ — ทั้งที่จองเอง และที่เพื่อนจองให้
     *
     * @return array<int, array<string, mixed>>
     */
    private function bookings(User $user): array
    {
        $ids = Booking::where('user_id', $user->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->pluck('id');

        $memberIds = BookingMember::where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereHas('booking', fn ($q) => $q->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
            ->pluck('booking_id');

        $today = now('Asia/Bangkok')->startOfDay();

        return Booking::with([
            'schedule.trip', 'schedule.vehicle.driver', 'schedule.pickupPoints',
            'pickupPoint', 'passengers', 'installmentPayments',
        ])
            ->whereIn('id', $ids->merge($memberIds)->unique())
            ->whereHas('schedule', fn ($q) => $q
                ->whereDate('departure_date', '>=', $today->copy()->subDays(self::RECENT_PAST_DAYS)))
            ->get()
            ->sortBy(fn (Booking $booking) => $booking->schedule?->departure_date)
            ->take(self::MAX_BOOKINGS)
            ->map(fn (Booking $booking) => $this->bookingPayload($user, $booking, $today))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(User $user, Booking $booking, CarbonInterface $today): array
    {
        $schedule = $booking->schedule;
        $trip = $schedule?->trip;
        $isOwner = $booking->user_id === $user->id;

        $facts = $schedule ? $this->facts->forUser($user, $schedule) : [];
        $daysUntil = $schedule?->departure_date
            ? $today->diffInDays($schedule->departure_date->copy()->startOfDay(), false)
            : null;

        $payload = [
            'booking_ref' => $booking->booking_ref,
            'trip_title' => $trip?->title,
            'trip_slug' => $trip?->slug,
            'status' => $booking->status,
            'departure_label' => $schedule?->departureLabelThai(),
            'days_until_departure' => $daysUntil,
            'is_past' => $daysUntil !== null && $daysUntil < 0,
            // คนที่เพื่อนจองให้เห็นข้อมูลเดินทางได้ แต่ไม่ใช่เจ้าของยอดเงิน
            'my_role' => $isOwner ? 'ผู้จอง' : 'ผู้ร่วมเดินทาง (เพื่อนเป็นคนจอง)',
            'traveller_count' => $booking->passengers->count(),
            'checked_in' => (bool) $booking->checked_in,
            'pickup' => $facts['pickup'] ?? null,
            'pickup_pending_note' => ($facts['pickup'] ?? null) ? null : TripFactsService::PENDING_PICKUP,
            'vehicle' => $facts['vehicle'] ?? null,
            'vehicle_pending_note' => ($facts['vehicle'] ?? null) ? null : TripFactsService::PENDING_VEHICLE,
            'driver' => $facts['driver'] ?? null,
            'driver_pending_note' => ($facts['driver'] ?? null) ? null : TripFactsService::PENDING_DRIVER,
            'staff' => $facts['staff'] ?? [],
            'staff_pending_note' => ($facts['staff'] ?? []) ? null : TripFactsService::PENDING_STAFF,
        ];

        if ($isOwner) {
            $payload['payment'] = $this->payment($booking);
        }

        if ($trip) {
            $payload['trip_info'] = $this->tripInfo($trip);
        }

        return $payload;
    }

    /**
     * ยอดเงินของการจองนี้ — พอให้ตอบ "ค้างเท่าไหร่ จ่ายวันไหน" ได้โดยไม่ต้องเปิดแอป
     *
     * @return array<string, mixed>
     */
    private function payment(Booking $booking): array
    {
        $total = (float) $booking->total_amount;
        $paid = (float) $booking->paid_amount;

        return [
            'payment_type' => $booking->payment_type,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'outstanding_amount' => max(0, round($total - $paid, 2)),
            'balance_due_label' => $booking->balance_due_at
                ? ThaiDate::full($booking->balance_due_at)
                : null,
            'balance_paid' => (bool) $booking->balance_paid_at,
            'installments' => $booking->installmentPayments
                ->map(fn ($installment) => [
                    'no' => $installment->installment_no,
                    'amount' => (float) $installment->amount,
                    'due_label' => $installment->due_date ? ThaiDate::full($installment->due_date) : null,
                    'status' => $installment->status,
                ])
                ->all(),
            'refund_status' => $booking->refund_status,
        ];
    }

    /**
     * ข้อมูลทริปที่ตอบคำถาม "ต้องเตรียมอะไร / รวมอะไรบ้าง" ได้
     *
     * ตัดคำบรรยายยาว ๆ ทิ้ง เพราะกินโทเคนโดยไม่ช่วยให้ตอบตรงขึ้น
     *
     * @return array<string, mixed>
     */
    private function tripInfo(Trip $trip): array
    {
        return array_filter([
            'location' => $trip->location,
            'difficulty' => $trip->difficulty,
            'duration_days' => (int) $trip->duration_days,
            'distance_km' => $trip->distance_km ? (float) $trip->distance_km : null,
            'elevation_gain_m' => $trip->elevation_gain_m ? (int) $trip->elevation_gain_m : null,
            'inclusions' => $trip->inclusions,
            'exclusions' => $trip->exclusions,
            'preparations' => $trip->preparations,
            'must_know' => $trip->must_know,
            'faqs' => $trip->faqs,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(string $question, array $history): array
    {
        $messages = collect($history)
            ->filter(fn ($turn) => in_array($turn['role'] ?? null, ['user', 'assistant'], true))
            ->map(fn ($turn) => ['role' => $turn['role'], 'content' => (string) $turn['content']])
            ->take(-self::MAX_HISTORY)
            ->values()
            ->all();

        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => [
                    'type' => 'string',
                    'description' => 'คำตอบภาษาไทยแบบเป็นกันเอง สั้นกระชับ 1-4 ประโยค',
                ],
                'actions' => [
                    'type' => 'array',
                    'description' => 'ปุ่มลัดที่ควรเสนอ สูงสุด 3 ปุ่ม ถ้าไม่มีที่เกี่ยวข้องให้ส่ง []',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => array_keys(self::ACTIONS),
                            ],
                            'label' => [
                                'type' => 'string',
                                'description' => 'ข้อความบนปุ่มเป็นภาษาไทย สั้น ๆ ไม่เกิน 20 ตัวอักษร',
                            ],
                            'booking_ref' => [
                                'type' => ['string', 'null'],
                                'description' => 'เลขที่จองที่ปุ่มนี้อ้างถึง ถ้าไม่เจาะจงให้เป็น null',
                            ],
                        ],
                        'required' => ['type', 'label', 'booking_ref'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['reply', 'actions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bookings
     */
    private function systemPrompt(User $user, array $bookings): string
    {
        $now = now('Asia/Bangkok');
        $name = trim((string) ($user->nickname ?: $user->name)) ?: 'ลูกค้า';
        $json = json_encode($bookings, JSON_UNESCAPED_UNICODE) ?: '[]';
        $actions = collect(self::ACTIONS)
            ->map(fn ($label, $key) => "- {$key}: {$label}")
            ->implode("\n");

        $empty = $bookings === []
            ? "\nตอนนี้ผู้ใช้ยังไม่มีการจองที่กำลังจะถึง ถ้าถามเรื่องการเดินทางให้บอกตรง ๆ ว่ายังไม่พบการจอง\nแล้วชวนให้ติดต่อทีมงานถ้าคิดว่าน่าจะมี\n"
            : '';

        return <<<PROMPT
        คุณคือผู้ช่วยส่วนตัวของ "ลุยเลเขา" แพลตฟอร์มทริปเดินป่าและกิจกรรมกลางแจ้งในไทย
        คุณกำลังคุยกับคุณ{$name} ซึ่งล็อกอินอยู่ หน้าที่ของคุณคือตอบคำถามเกี่ยวกับ
        "การเดินทางของเขาเอง" ไม่ใช่การขายทริปใหม่

        ตอนนี้คือ {$now->format('Y-m-d H:i')} (เวลาประเทศไทย)

        นี่คือการจองของผู้ใช้คนนี้ทั้งหมดที่ยังเกี่ยวข้อง ในรูปแบบ JSON:
        {$json}
        {$empty}
        คำอธิบายฟิลด์:
        - days_until_departure: จำนวนวันจากวันนี้ถึงวันเดินทาง (ติดลบ = ผ่านไปแล้ว)
        - my_role: บอกว่าเป็นคนจองเอง หรือเพื่อนจองให้ (ผู้ร่วมเดินทางจะไม่มีข้อมูล payment)
        - outstanding_amount: ยอดที่ยังค้างอยู่ (บาท)
        - *_pending_note: แปลว่าระบบยังไม่รู้ข้อมูลนั้น ให้ตอบตามข้อความในฟิลด์นี้
        - status: pending = จองแล้วรอชำระ/รอตรวจสลิป, confirmed = ยืนยันแล้ว

        กฎการตอบ:
        - ตอบจากข้อมูลข้างบนเท่านั้น ห้ามแต่งเวลา ทะเบียนรถ ชื่อคนขับ ยอดเงิน หรือจุดรับ
        - ถ้าข้อมูลยังไม่มี (มี *_pending_note) ให้บอกตรง ๆ ว่ายังไม่ได้ยืนยัน
          พร้อมบอกว่าทีมงานจะแจ้งเมื่อไหร่ อย่าเดา
        - ถ้ามีหลายการจอง ให้เดาจากบริบทว่าเขาหมายถึงรอบไหน (ปกติคือรอบที่ใกล้ที่สุด)
          ถ้ายังกำกวมจริง ๆ ให้ถามกลับสั้น ๆ ว่าหมายถึงทริปไหน
        - เรื่องนโยบายยกเลิก/คืนเงิน/เลื่อนวัน คุณตัดสินใจแทนทีมงานไม่ได้
          ให้บอกว่าต้องคุยกับทีมงาน แล้วเสนอปุ่ม support
        - ห้ามสัญญาสิ่งที่ระบบทำไม่ได้ เช่น ยกเลิกให้ เลื่อนวันให้ คืนเงินให้ หรือต่อรองราคา
        - ถ้าถามเรื่องที่ไม่เกี่ยวกับทริปหรือการจอง ให้ตอบสั้น ๆ ว่าช่วยเรื่องทริปได้ แล้ว actions เป็น []

        โทนการเขียน:
        - ภาษาไทย เป็นกันเองแต่สุภาพ ใช้ "คุณ" และลงท้ายด้วย "ครับ"
        - ตอบให้จบใน 1-4 ประโยค ตอบคำถามที่ถามก่อน แล้วค่อยเสริมถ้าจำเป็น
        - วันที่พูดเป็นภาษาคน ("พรุ่งนี้", "อีก 3 วัน") ไม่ต้องอ่าน ISO date ให้ฟัง
        - อย่าลิสต์ข้อมูลทั้งหมดที่มี ให้เลือกเฉพาะที่ตอบคำถามนั้น

        ปุ่มลัดที่ใช้ได้ (ใส่ใน actions สูงสุด 3 ปุ่ม เรียงจากที่น่ากดที่สุด):
        {$actions}

        ใส่ booking_ref ทุกครั้งที่ปุ่มนั้นเจาะจงการจองใดการจองหนึ่ง โดยใช้เลขที่จองจริง
        จากข้อมูลข้างบนเท่านั้น ถ้าปุ่มไม่เจาะจงให้เป็น null
        PROMPT;
    }

    /**
     * ตัวช่วยเล็ก ๆ ให้ controller เอาไปคืนเป็นคำถามตัวอย่างบนหน้าจอเปล่า
     *
     * @return Collection<int, string>
     */
    public function suggestions(User $user): Collection
    {
        $hasUpcoming = Booking::where('user_id', $user->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->whereHas('schedule', fn ($q) => $q
                ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString()))
            ->exists();

        return $hasUpcoming
            ? collect([
                'ทริปหน้าออกเดินทางกี่โมง',
                'ต้องเตรียมอะไรไปบ้าง',
                'ยอดคงเหลือเท่าไหร่ จ่ายวันไหน',
                'ขึ้นรถที่ไหน',
            ])
            : collect([
                'ฉันมีทริปที่จองไว้ไหม',
                'ทริปที่ผ่านมาของฉันมีอะไรบ้าง',
                'ติดต่อทีมงานยังไง',
            ]);
    }
}
