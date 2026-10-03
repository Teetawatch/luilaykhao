<?php

namespace App\Services;

use App\Jobs\SendUnderfilledTripWarningsJob;
use App\Models\Booking;
use App\Models\FlexiDepartureOffer;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\TripSchedule;
use App\Support\SiteSettings;
use App\Support\ThaiDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "เรดาร์รอบเสี่ยงไม่ออก" — รวมรอบที่ใกล้วันเดินทางแต่ยังจองไม่ถึงขั้นต่ำที่รถออก
 * ไว้ที่เดียว พร้อมบริบทที่ต้องใช้ตัดสินใจว่าจะดันต่อหรือยุบรอบ
 *
 * เหตุผลที่ต้องมี: เครื่องมือแก้ปัญหามีครบอยู่แล้ว (ลดราคารอบ, ชวนช่วยกันเปิดรอบ,
 * Flexi-Price, ย้ายการจอง) แต่กระจายอยู่คนละหน้า และไม่มีอะไรบอกทีมงานว่า
 * "รอบนี้กำลังจะไม่ออก" จนกระทั่งเมลเตือนลูกค้ายิงที่ D-7 ซึ่งเหลือเวลาแก้น้อยมาก
 * เรดาร์เริ่มจับตาตั้งแต่ D-21 เพื่อให้ยังมีเวลาขายที่นั่งที่เหลือ
 *
 * เกณฑ์ขั้นต่ำอ่านจาก underfilled_min_seats (ตัวเลขฝั่งปฏิบัติการ) ให้ตรงกับ
 * SendUnderfilledTripWarningsJob ที่ยิงเมลหาลูกค้า — คนละตัวกับ guarantee_min_seats
 * ที่ใช้ตัดสินป้ายสถานะฝั่งลูกค้า ถึงค่าเริ่มต้นจะเท่ากันก็ตาม
 */
class AtRiskScheduleService
{
    /** เริ่มจับตากี่วันก่อนเดินทาง */
    public const WINDOW_DAYS = 21;

    /** เหลือน้อยกว่านี้ = แดง ต้องตัดสินใจวันนี้ */
    public const CRITICAL_DAYS = 7;

    /** เหลือน้อยกว่านี้ = ส้ม ควรลงมือแล้ว */
    public const WARNING_DAYS = 14;

    /** ห่างจากรอบเสี่ยงได้ไม่เกินกี่วันจึงเสนอเป็นรอบให้ย้ายไปรวมกัน */
    private const MERGE_WINDOW_DAYS = 21;

    /** เว้นระยะก่อนกดชวนซ้ำได้อีกครั้ง — กันรบกวนลูกค้ากลุ่มเดิมถี่เกินไป */
    public const NUDGE_COOLDOWN_HOURS = 24;

    public function __construct(
        private SmsService $sms,
    ) {}

    public function minSeats(): int
    {
        return max(1, SiteSettings::int('underfilled_min_seats'));
    }

    /**
     * รอบที่เสี่ยงไม่ออกทั้งหมดในกรอบเวลา เรียงจากที่เหลือเวลาน้อยที่สุด
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function atRisk(?int $windowDays = null): Collection
    {
        $window = $windowDays ?? self::WINDOW_DAYS;
        $minSeats = $this->minSeats();
        $today = Carbon::now('Asia/Bangkok')->startOfDay();

        $schedules = TripSchedule::query()
            // price_per_person จำเป็นสำหรับ effective_price เมื่อรอบไม่ได้ตั้งราคาทับ
            ->with('trip:id,title,slug,price_per_person')
            ->whereNotNull('departure_date')
            ->whereDate('departure_date', '>=', $today->toDateString())
            ->whereDate('departure_date', '<=', $today->copy()->addDays($window)->toDateString())
            ->where('status', '!=', 'cancelled')
            ->where('is_charter', false)
            ->where('booked_seats', '<', $minSeats)
            ->orderBy('departure_date')
            ->get();

        if ($schedules->isEmpty()) {
            return collect();
        }

        $context = $this->contextFor($schedules);

        return $schedules
            ->map(fn (TripSchedule $s) => $this->row($s, $minSeats, $today, $context))
            // รอบที่มีคนจองแล้วมาก่อนเสมอ — มีลูกค้าที่ต้องได้คำตอบ
            // จากนั้นเรียงตามเวลาที่เหลือ
            ->sortBy(fn (array $row) => [$row['booked_seats'] > 0 ? 0 : 1, $row['days_left']])
            ->values();
    }

    /**
     * ชวนผู้ที่จองรอบนี้แล้วช่วยกันหาเพื่อนมาเติม — แรงจูงใจของเขาแรงกว่าใคร
     * เพราะถ้าไม่ครบ ทริปของตัวเองจะถูกยกเลิก
     *
     * @return array{notified: int, skipped_reason: ?string}
     */
    public function sendRallyNudge(TripSchedule $schedule, bool $force = false): array
    {
        $schedule->loadMissing('trip');

        if ($schedule->is_charter) {
            throw new \Exception('รอบเหมาคันออกเดินทางแน่นอนอยู่แล้ว ไม่ต้องชวนเพิ่ม');
        }

        if ($schedule->status === 'cancelled') {
            throw new \Exception('รอบนี้ถูกยกเลิกไปแล้ว');
        }

        $seatsNeeded = max(0, $this->minSeats() - (int) $schedule->booked_seats);

        if ($seatsNeeded === 0) {
            throw new \Exception('รอบนี้ครบจำนวนออกเดินทางแล้ว');
        }

        if (! $force && $this->nudgeCooldownRemaining($schedule) > 0) {
            throw new \Exception('เพิ่งชวนไปเมื่อไม่นานนี้ รอครบ '.self::NUDGE_COOLDOWN_HOURS.' ชั่วโมงก่อนชวนซ้ำ');
        }

        $bookings = Booking::query()
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'booking_ref']);

        if ($bookings->isEmpty()) {
            throw new \Exception('รอบนี้ยังไม่มีการจอง จึงยังไม่มีใครให้ชวนช่วยเปิดรอบ');
        }

        $tripTitle = $schedule->trip?->title ?? 'ทริป';
        $dateLabel = $schedule->departureLabelThai();
        $notified = 0;

        // ผู้ใช้คนเดียวอาจมีหลายการจองในรอบเดียวกัน — ส่งครั้งเดียวพอ
        foreach ($bookings->unique('user_id') as $booking) {
            SmartNotification::send(
                (int) $booking->user_id,
                'schedule_rally_nudge',
                "ช่วยกันเปิดรอบ{$tripTitle}",
                "รอบ {$dateLabel} ขาดอีก {$seatsNeeded} ที่นั่งก็ออกเดินทางแน่นอนครับ "
                    .'ถ้ามีเพื่อนหรือครอบครัวที่สนใจ ชวนมาร่วมทางกันได้เลย กดดูวิธีชวนในใบจองของคุณ',
                [
                    'booking_ref' => $booking->booking_ref,
                    'schedule_id' => $schedule->id,
                    'route' => 'booking',
                ],
            );
            $notified++;
        }

        $schedule->forceFill(['rally_nudged_at' => now()])->save();

        return ['notified' => $notified, 'skipped_reason' => null];
    }

    /**
     * ข้อความตั้งต้นของ SMS "รอบนี้คนไม่ครบ ขอเงินคืนได้" — ทีมงานแก้ได้ก่อนกดส่ง
     *
     * พูดถึงอีเมลเฉพาะเมื่อเลย D-7 มาแล้ว (SendUnderfilledTripWarningsJob ยิงไปแล้วจริง)
     * ก่อนหน้านั้นลูกค้ายังไม่ได้อีเมลอะไร ห้ามอ้างถึง
     */
    public function underfilledSmsTemplate(TripSchedule $schedule): string
    {
        $schedule->loadMissing('trip');
        $daysBefore = SendUnderfilledTripWarningsJob::DAYS_BEFORE;

        return sprintf(
            'รอบเดินทาง %s %s มีผู้เดินทางเพียง %d ท่านครับ ขั้นต่ำรถออก %d ท่าน%s ทั้งนี้สามารถแจ้งเลขบัญชีเพื่อรับเงินคืนเต็มจำนวนได้เลยที่ไลน์ %s ครับ',
            Str::limit(trim((string) $schedule->trip?->title), 45, ''),
            ThaiDate::short($schedule->departure_date),
            (int) $schedule->booked_seats,
            $this->minSeats(),
            $this->daysLeft($schedule) <= $daysBefore
                ? " เราได้ส่งอีเมลแจ้งไปแล้วเมื่อ {$daysBefore} วันก่อนเดินทาง"
                : '',
            config('app.support_line_id'),
        );
    }

    /**
     * ใครจะได้ SMS บ้าง — ใบจองที่จ่ายเงินมาแล้ว (ข้อความพูดเรื่องคืนเงิน
     * ใบที่ยังไม่จ่ายสักบาทไม่มีอะไรให้คืน) พร้อมสถานะของแต่ละใบ
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function underfilledSmsRecipients(TripSchedule $schedule): Collection
    {
        $bookings = Booking::query()
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('paid_amount', '>', 0)
            ->with(['user', 'passengers'])
            ->orderBy('id')
            ->get();

        $logs = SmsLog::query()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->where('sms_type', SmsService::UNDERFILLED_NOTICE)
            ->where('dedupe_key', SmsService::underfilledNoticeKey($schedule->id))
            ->where('status', '!=', 'skipped')
            ->get()
            ->keyBy('booking_id');

        return $bookings->map(function (Booking $booking) use ($logs) {
            $phone = $this->sms->recipientFor($booking);
            $log = $logs->get($booking->id);

            return [
                'booking_id' => $booking->id,
                'booking_ref' => $booking->booking_ref,
                'name' => $booking->passengers->first()?->name ?? $booking->user?->name ?? '-',
                'phone' => $phone ? $this->displayPhone($phone) : null,
                // ready = จะส่งรอบนี้ · sent/pending/failed = เคยส่งไปแล้ว (failed ระบบลองซ้ำเอง)
                'status' => $log?->status ?? ($phone ? 'ready' : 'no_phone'),
                'sent_at' => $log?->sent_at?->toISOString(),
            ];
        })->values();
    }

    /**
     * ส่ง SMS แจ้งลูกค้าทุกใบของรอบที่ยังไม่เคยได้รับ
     *
     * @return array{sent: int, queued: int, failed: int, no_phone: int, already: int}
     */
    public function sendUnderfilledSms(TripSchedule $schedule, string $message): array
    {
        if ($schedule->status === 'cancelled') {
            throw new \Exception('รอบนี้ถูกยกเลิกไปแล้ว');
        }

        if ($schedule->is_charter) {
            throw new \Exception('รอบเหมาคันออกเดินทางแน่นอนอยู่แล้ว ไม่ต้องแจ้งเรื่องคนไม่ครบ');
        }

        if ((int) $schedule->booked_seats >= $this->minSeats()) {
            throw new \Exception('รอบนี้ครบจำนวนออกเดินทางแล้ว');
        }

        $recipients = $this->underfilledSmsRecipients($schedule);

        if ($recipients->isEmpty()) {
            throw new \Exception('รอบนี้ยังไม่มีใบจองที่ชำระเงินแล้ว จึงไม่มีใครให้แจ้ง');
        }

        $ready = $recipients->where('status', 'ready');

        if ($ready->isEmpty()) {
            throw new \Exception('ส่ง SMS ถึงทุกคนที่มีเบอร์โทรในรอบนี้ไปแล้ว');
        }

        // บันทึก skipped ของครั้งก่อน (ตอนนั้นยังไม่มีเบอร์ / ปิดชนิดนี้ไว้) จะทำให้
        // firstOrCreate คืนแถวเก่าแทนการส่งจริง — ล้างทิ้งก่อน
        SmsLog::query()
            ->whereIn('booking_id', $ready->pluck('booking_id'))
            ->where('sms_type', SmsService::UNDERFILLED_NOTICE)
            ->where('dedupe_key', SmsService::underfilledNoticeKey($schedule->id))
            ->where('status', 'skipped')
            ->delete();

        $result = [
            'sent' => 0,
            'queued' => 0,
            'failed' => 0,
            'no_phone' => $recipients->where('status', 'no_phone')->count(),
            'already' => $recipients->whereNotIn('status', ['ready', 'no_phone'])->count(),
        ];

        $bookings = Booking::query()
            ->whereIn('id', $ready->pluck('booking_id'))
            ->with(['user', 'passengers', 'schedule.trip'])
            ->get();

        foreach ($bookings as $booking) {
            $log = $this->sms->sendUnderfilledNotice($booking, $message);

            match ($log?->status) {
                'sent' => $result['sent']++,
                'pending' => $result['queued']++,
                default => $result['failed']++,
            };
        }

        return $result;
    }

    /** 66812345678 -> 081-234-5678 อ่านง่ายกว่าสำหรับทีมงาน */
    private function displayPhone(string $phone): string
    {
        if (str_starts_with($phone, '66') && strlen($phone) === 11) {
            $local = '0'.substr($phone, 2);

            return substr($local, 0, 3).'-'.substr($local, 3, 3).'-'.substr($local, 6);
        }

        return $phone;
    }

    private function daysLeft(TripSchedule $schedule): int
    {
        $today = Carbon::now('Asia/Bangkok')->startOfDay();

        return (int) $today->diffInDays($schedule->departure_date->copy()->startOfDay(), false);
    }

    /** เหลืออีกกี่ชั่วโมงจึงกดชวนซ้ำได้ (0 = กดได้เลย) */
    public function nudgeCooldownRemaining(TripSchedule $schedule): int
    {
        if ($schedule->rally_nudged_at === null) {
            return 0;
        }

        $readyAt = $schedule->rally_nudged_at->copy()->addHours(self::NUDGE_COOLDOWN_HOURS);

        return $readyAt->isFuture() ? (int) ceil(now()->diffInMinutes($readyAt) / 60) : 0;
    }

    /**
     * ดึงข้อมูลประกอบของทุกรอบพร้อมกันทีเดียว กัน N+1 บนหน้าที่โหลดถี่
     *
     * @param  Collection<int, TripSchedule>  $schedules
     * @return array<string, mixed>
     */
    private function contextFor(Collection $schedules): array
    {
        $ids = $schedules->pluck('id');

        return [
            'money' => Booking::query()
                ->whereIn('schedule_id', $ids)
                ->whereIn('status', ['pending', 'confirmed'])
                ->selectRaw('schedule_id, COUNT(*) as bookings_count, COALESCE(SUM(paid_amount), 0) as paid_total')
                ->groupBy('schedule_id')
                ->get()
                ->keyBy('schedule_id'),

            'flexi' => FlexiDepartureOffer::query()
                ->whereIn('schedule_id', $ids)
                ->where('status', FlexiDepartureOffer::STATUS_PENDING)
                ->get(['id', 'schedule_id', 'respond_by'])
                ->keyBy('schedule_id'),

            'merge' => $this->mergeCandidates($schedules),

            'underfilled_sms' => SmsLog::query()
                ->where('sms_type', SmsService::UNDERFILLED_NOTICE)
                ->whereIn('dedupe_key', $ids->map(fn ($id) => SmsService::underfilledNoticeKey($id)))
                ->whereIn('status', ['sent', 'pending'])
                ->selectRaw('dedupe_key, COUNT(*) as sms_count, MAX(created_at) as last_at')
                ->groupBy('dedupe_key')
                ->get()
                ->keyBy('dedupe_key'),
        ];
    }

    /**
     * รอบอื่นของทริปเดียวกันที่อยู่ใกล้ ๆ และยังมีที่นั่งว่าง — ถ้าย้ายคนมารวมกัน
     * รอบเดียวอาจครบขั้นต่ำแทนที่จะล่มทั้งคู่
     *
     * @param  Collection<int, TripSchedule>  $schedules
     * @return array<int, array<int, array<string, mixed>>> schedule_id => candidates
     */
    private function mergeCandidates(Collection $schedules): array
    {
        $tripIds = $schedules->pluck('trip_id')->unique();
        $today = Carbon::now('Asia/Bangkok')->startOfDay();

        // ไม่ตัดรอบที่เสี่ยงด้วยกันออก — สองรอบที่คนไม่ครบทั้งคู่ยุบรวมกันแล้ว
        // ครบขั้นต่ำ คือกรณีที่มีประโยชน์ที่สุด (ตัดเฉพาะตัวเองในลูปข้างล่าง)
        $siblings = TripSchedule::query()
            ->whereIn('trip_id', $tripIds)
            ->whereNotNull('departure_date')
            ->whereDate('departure_date', '>=', $today->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderBy('departure_date')
            ->get(['id', 'trip_id', 'departure_date', 'total_seats', 'booked_seats', 'is_charter']);

        $out = [];

        foreach ($schedules as $schedule) {
            $out[$schedule->id] = $siblings
                ->where('trip_id', $schedule->trip_id)
                ->filter(function (TripSchedule $sibling) use ($schedule) {
                    if ($sibling->id === $schedule->id || $sibling->is_charter) {
                        return false;
                    }

                    $seatsFree = (int) $sibling->total_seats - (int) $sibling->booked_seats;
                    if ($seatsFree < (int) $schedule->booked_seats) {
                        return false;   // รับคนจากรอบนี้ไม่หมด ไม่ช่วยอะไร
                    }

                    return abs($schedule->departure_date->diffInDays($sibling->departure_date)) <= self::MERGE_WINDOW_DAYS;
                })
                ->sortByDesc('booked_seats')
                ->take(3)
                ->map(fn (TripSchedule $sibling) => [
                    'id' => $sibling->id,
                    'departure_date' => $sibling->departure_date->toDateString(),
                    'departure_label' => $sibling->departureLabelThai(),
                    'booked_seats' => (int) $sibling->booked_seats,
                    'seats_free' => (int) $sibling->total_seats - (int) $sibling->booked_seats,
                    // ย้ายมารวมกันแล้วรอบปลายทางจะครบขั้นต่ำเลยไหม
                    'reaches_minimum' => ((int) $sibling->booked_seats + (int) $schedule->booked_seats) >= $this->minSeats(),
                ])
                ->values()
                ->all();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function row(TripSchedule $schedule, int $minSeats, Carbon $today, array $context): array
    {
        $daysLeft = (int) $today->diffInDays($schedule->departure_date->copy()->startOfDay(), false);
        $booked = (int) $schedule->booked_seats;
        $money = $context['money'][$schedule->id] ?? null;
        $flexi = $context['flexi'][$schedule->id] ?? null;
        $sms = $context['underfilled_sms'][SmsService::underfilledNoticeKey($schedule->id)] ?? null;

        return [
            'id' => $schedule->id,
            'trip_id' => $schedule->trip_id,
            'trip_title' => $schedule->trip?->title ?? 'ทริป',
            'trip_slug' => $schedule->trip?->slug,
            'departure_date' => $schedule->departure_date->toDateString(),
            'departure_label' => $schedule->departureLabelThai(),
            'days_left' => $daysLeft,
            'booked_seats' => $booked,
            'total_seats' => (int) $schedule->total_seats,
            'min_seats' => $minSeats,
            'seats_needed' => max(0, $minSeats - $booked),
            'seats_available' => max(0, (int) $schedule->total_seats - $booked),
            'bookings_count' => (int) ($money->bookings_count ?? 0),
            'revenue_at_risk' => round((float) ($money->paid_total ?? 0), 2),
            'severity' => $this->severity($daysLeft, $booked),
            // ราคาที่ขายอยู่จริงตอนนี้ — หน้าเรดาร์ใช้ตั้งราคาลดโค้งท้ายต่อจากนี้
            'current_price' => round((float) $schedule->effective_price, 2),
            'flash_sale_active' => $schedule->flashSaleActive(),
            'flexi_offer' => $flexi ? [
                'id' => $flexi->id,
                'respond_by' => $flexi->respond_by?->toISOString(),
            ] : null,
            'rally_nudged_at' => $schedule->rally_nudged_at?->toISOString(),
            'rally_cooldown_hours' => $this->nudgeCooldownRemaining($schedule),
            'merge_candidates' => $context['merge'][$schedule->id] ?? [],
            'underfilled_sms_count' => (int) ($sms->sms_count ?? 0),
            'underfilled_sms_at' => $sms?->last_at ? Carbon::parse($sms->last_at)->toISOString() : null,
        ];
    }

    /**
     * ความเร่งด่วน — ยิ่งใกล้วันเดินทางยิ่งแดง แต่รอบที่ยังไม่มีใครจองเลย
     * ไม่ใช่เรื่องเร่งด่วน เพราะยกเลิกได้โดยไม่มีลูกค้าเสียหาย
     */
    private function severity(int $daysLeft, int $bookedSeats): string
    {
        if ($bookedSeats === 0) {
            return 'low';
        }

        if ($daysLeft <= self::CRITICAL_DAYS) {
            return 'critical';
        }

        if ($daysLeft <= self::WARNING_DAYS) {
            return 'high';
        }

        return 'medium';
    }
}
