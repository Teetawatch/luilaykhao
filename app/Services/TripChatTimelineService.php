<?php

namespace App\Services;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Models\ChatMessage;
use App\Models\ChatRestStop;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Models\WeatherForecast;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ข้อความอัตโนมัติในห้องแชทตามไทม์ไลน์ของทริป
 *
 * ห้องแชทเดิมเงียบจนกว่าจะมีคนพิมพ์ ทั้งที่ช่วงก่อนเดินทางคือช่วงที่ลูกค้าอยาก
 * รู้มากที่สุด บริการนี้จึงโพสต์ข้อความระบบตามจังหวะของทริป (นับถอยหลัง →
 * เตรียมของ → ทีมงาน+คนขับพร้อมเบอร์โทร → คำถามที่พบบ่อย → อากาศ →
 * จุดรับคืนก่อนเดินทาง → เช้าวันเดินทาง → [ทริปหลายวัน: สรุปพรุ่งนี้ทุกคืน] →
 * ปิดทริป → เตือนก่อนห้องแชทถูกลบ)
 *
 * หลักการ
 * - ทุกข้อความมี `system_key` ประจำห้อง (unique ระดับ DB) → job ยิงซ้ำกี่รอบก็ไม่ซ้ำ
 * - เวลาทั้งหมดคิดเป็นเวลาไทย เพราะ departs_at เก็บเป็น wall-clock ไทย
 *   (ดู SendDepartureSoonRemindersJob) ส่วน timezone ของแอปเป็น UTC
 * - โพสต์เฉพาะที่ "ถึงเวลาแล้วและยังไม่เกินช่วงผ่อนผัน" — ห้องที่เพิ่งมีคนจอง
 *   ก่อนเดินทาง 2 วัน จึงไม่โดนเทข้อความย้อนหลังทั้งไทม์ไลน์
 * - ข้อความที่ต้องรอข้อมูลของแอดมิน (สรุปทีมงาน/คนขับ) ผ่อนผันยาวถึงเวลารถออก
 *   และคืน null ระหว่างที่ข้อมูลยังไม่ครบ — พอครบก็ลงห้องในรอบ job ถัดไปเลย
 * - ไม่ยิง push (FCM) เพราะมี job เตือนก่อนเดินทาง/เช็คอิน/รีวิว ยิง push อยู่แล้ว
 *   ข้อความพวกนี้ทำหน้าที่เป็นบันทึกในห้อง + badge ยังไม่อ่านของแท็บแชท
 */
class TripChatTimelineService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** ช่วงผ่อนผันมาตรฐาน — เลยเวลาไปเกินนี้ถือว่าตกขบวน ไม่ต้องโพสต์ย้อนหลัง */
    private const DEFAULT_GRACE_HOURS = 24;

    /** ก่อนรถออกกี่ชั่วโมงถึงยอมโพสต์สรุปทีมงาน "เท่าที่มี" แทนการรอจนข้อมูลครบ */
    private const CREW_LAST_CALL_HOURS = 3;

    /** ยกคำถามที่พบบ่อยลงห้องแชทกี่ข้อ (ที่เหลืออ่านต่อในแอป) */
    private const FAQ_LIMIT = 5;

    /**
     * สรุปพรุ่งนี้ของทริปหลายวัน — คีย์ day_brief_{N} (N = วันที่ของทริป เริ่มที่ 2
     * เพราะวันแรกมี pickup_eve/departure_soon ดูแลอยู่แล้ว) โพสต์คืนก่อนวันนั้น
     */
    public const DAY_BRIEF_PREFIX = 'day_brief_';

    /** โพสต์สรุปพรุ่งนี้ตอนกี่โมง (เวลาไทย) — ตรงกับเตือนนัดรวมพลคืนก่อน */
    public const DAY_BRIEF_HOUR = 20;

    /** เลยเที่ยงคืนไปแล้วไม่ต้องโพสต์ — "พรุ่งนี้" ในข้อความจะผิดวันทันที */
    private const DAY_BRIEF_GRACE_HOURS = 3;

    /** ทริปยาวกว่านี้ไม่ไล่สร้างคีย์ต่อ (กันข้อมูลวันกลับผิดแล้ววนสร้างเป็นร้อยคีย์) */
    private const DAY_BRIEF_MAX_DAYS = 21;

    /** แผนพรุ่งนี้ยกลงห้องกี่รายการ ที่เหลือชี้ไปปุ่มกำหนดการ */
    private const DAY_BRIEF_ITEM_LIMIT = 12;

    /**
     * ข้อความ "เตรียมตัวเดินทาง" ที่เก็บไว้ก่อนระหว่างรอบยังไม่ครบขั้นต่ำ — อ่านแล้ว
     * เหมือนทริปไปปกติ (ดู TripSchedule::holdsTravelMessages) ห้องได้ข้อความ
     * "คนยังไม่ครบ" จาก ChatRoomEventService แทน ข้อความที่ผ่อนผันยาวถึงเวลารถออก
     * (ทีมงาน/กำหนดการ/คำถาม) จะลงห้องเองเมื่อรอบครบหรือทีมงานยืนยันว่าออก
     *
     * ไม่รวมข้อความวันเดินทาง (departure_soon) — ถึงวันนั้นรอบที่ยังไม่ถูกยกเลิก
     * คือรอบที่ออกจริง และข้อความหลังทริปไม่เกี่ยว
     */
    private const HELD_WHILE_UNDERFILLED = [
        'countdown_7d',
        'prepare_3d',
        'itinerary_2d',
        'crew_contacts',
        'trip_faq',
        'weather_2d',
        'pickup_eve',
    ];

    public function __construct(
        private ChatService $chatService,
        private WeatherService $weatherService,
        private TripFactsService $facts,
        private ScheduleItineraryService $itinerary,
    ) {}

    /**
     * คีย์ทั้งหมดตามลำดับเวลา — ใช้ในเทสและเวลาดีบัก
     * (ไม่รวม day_brief_{N} ซึ่งมีจำนวนตามความยาวของทริป)
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return [
            'countdown_7d',
            'prepare_3d',
            'itinerary_2d',
            'crew_contacts',
            'trip_faq',
            'weather_2d',
            'pickup_eve',
            'departure_soon',
            'trip_end',
            'photos_expiring',
        ];
    }

    /**
     * โพสต์ข้อความไทม์ไลน์ที่ถึงกำหนดของรอบนี้ (ที่ยังไม่เคยโพสต์)
     *
     * @return array<int, string> คีย์ที่เพิ่งโพสต์ในรอบนี้
     */
    public function syncFor(TripSchedule $schedule, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::TIMEZONE);

        if ($schedule->status === 'cancelled') {
            return [];
        }

        $posted = [];
        $held = $schedule->holdsTravelMessages();
        $existing = ChatMessage::where('schedule_id', $schedule->id)
            ->whereNotNull('system_key')
            ->pluck('system_key')
            ->all();

        foreach ($this->due($schedule, $now) as $key) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            if ($held && in_array($key, self::HELD_WHILE_UNDERFILLED, true)) {
                continue;
            }

            $body = $this->body($key, $schedule, $now);
            if ($body === null) {
                continue;   // ข้อมูลไม่พอสำหรับข้อความนี้ (เช่น ยังไม่มีพยากรณ์อากาศ)
            }

            // ห้องต้องมีข้อความต้อนรับก่อน ไม่งั้นห้องจะเริ่มด้วยข้อความอัตโนมัติ
            $this->chatService->ensureWelcome($schedule);

            $this->chatService->postSystem($schedule, $body, $key);
            $posted[] = $key;
        }

        if ($posted !== []) {
            Log::info('TripChatTimeline: โพสต์ข้อความอัตโนมัติ', [
                'schedule_id' => $schedule->id,
                'keys' => $posted,
            ]);
        }

        return $posted;
    }

    /**
     * คีย์ที่ "ถึงเวลาแล้วและยังอยู่ในช่วงผ่อนผัน" ณ เวลา $now
     *
     * @return array<int, string>
     */
    private function due(TripSchedule $schedule, CarbonImmutable $now): array
    {
        $out = [];

        foreach ($this->scheduleTimes($schedule) as $key => [$dueAt, $graceHours]) {
            if ($dueAt === null || $now->lt($dueAt) || $now->gt($dueAt->addHours($graceHours))) {
                continue;
            }

            $out[] = $key;
        }

        return $out;
    }

    /**
     * เวลาที่แต่ละข้อความควรถูกโพสต์ (เวลาไทย) พร้อมช่วงผ่อนผันของข้อความนั้น
     *
     * @return array<string, array{0: ?CarbonImmutable, 1: int}>
     */
    private function scheduleTimes(TripSchedule $schedule): array
    {
        $departureDate = $schedule->departure_date
            ? CarbonImmutable::parse($schedule->departure_date->toDateString(), self::TIMEZONE)
            : null;

        // เวลาออกรถจริง (อาจเป็นคืนก่อนวันทริป)
        $departsAt = $this->departsAtThai($schedule);

        $endDate = $schedule->return_date
            ? CarbonImmutable::parse($schedule->return_date->toDateString(), self::TIMEZONE)
            : $departureDate;

        // สรุปทีมงาน/คนขับและคำถามที่พบบ่อยรอข้อมูลได้จนถึงเวลารถออก — แอดมินมัก
        // ยืนยันสตาฟและคนขับช้ากว่า 2 วันก่อนเดินทาง ถ้าใช้ผ่อนผัน 24 ชม.
        // ตามปกติ ห้องที่ข้อมูลมาช้าจะไม่ได้ข้อความนี้เลย
        $graceUntilDeparture = fn (?CarbonImmutable $dueAt): int => ($dueAt === null || $departsAt === null || $departsAt->lte($dueAt))
            ? self::DEFAULT_GRACE_HOURS
            : max(1, (int) ceil(abs($dueAt->diffInHours($departsAt))));

        // กำหนดการก็รอข้อมูลของแอดมินเหมือนสรุปทีมงาน — บางรอบเขียนกำหนดการเสร็จ
        // เอาวันสองวันก่อนเดินทาง จึงผ่อนผันยาวถึงเวลารถออกเช่นกัน
        $itineraryDueAt = $departureDate?->subDays(2)->setTime(9, 0);
        $crewDueAt = $departureDate?->subDays(2)->setTime(10, 0);
        $faqDueAt = $departureDate?->subDays(2)->setTime(10, 30);

        return [
            'countdown_7d' => [$departureDate?->subDays(7)->setTime(9, 0), self::DEFAULT_GRACE_HOURS],
            'prepare_3d' => [$departureDate?->subDays(3)->setTime(19, 0), self::DEFAULT_GRACE_HOURS],
            'itinerary_2d' => [$itineraryDueAt, $graceUntilDeparture($itineraryDueAt)],
            'crew_contacts' => [$crewDueAt, $graceUntilDeparture($crewDueAt)],
            'trip_faq' => [$faqDueAt, $graceUntilDeparture($faqDueAt)],
            'weather_2d' => [$departureDate?->subDays(2)->setTime(18, 0), self::DEFAULT_GRACE_HOURS],
            // คืนก่อนออกเดินทางจริง 20:00 — ยึดวันที่รถออก ไม่ใช่วันทริป
            'pickup_eve' => [$departsAt?->startOfDay()->subDay()->setTime(20, 0), 11],
            // 3 ชั่วโมงก่อนรถออก — ผ่อนผันสั้น ๆ จะได้ไม่โพสต์หลังรถออกไปแล้วนาน
            'departure_soon' => [$departsAt?->subHours(3), 3],
            'trip_end' => [$endDate?->setTime(20, 5), self::DEFAULT_GRACE_HOURS],
            // ห้องแชทถูกลบ 3 วันหลังจบทริป (PurgeEndedTripChatsJob) — เตือนก่อน 1 วัน
            'photos_expiring' => [
                $endDate?->addDays(PurgeEndedTripChatsJob::DELETE_AFTER_DAYS - 1)->setTime(10, 0),
                self::DEFAULT_GRACE_HOURS,
            ],
            // ทริปหลายวัน: คืนก่อนวันที่ 2, 3, … สรุปแผน/นัด/อากาศ/ของที่ต้องเตรียมของพรุ่งนี้
            ...$this->dayBriefTimes($departureDate, $endDate),
        ];
    }

    /**
     * เนื้อข้อความของแต่ละคีย์ — null = ยังไม่มีข้อมูลพอ ให้ข้ามไปก่อน
     */
    private function body(string $key, TripSchedule $schedule, CarbonImmutable $now): ?string
    {
        if (str_starts_with($key, self::DAY_BRIEF_PREFIX)) {
            return $this->dayBriefBody($schedule, (int) substr($key, strlen(self::DAY_BRIEF_PREFIX)));
        }

        return match ($key) {
            'countdown_7d' => $this->countdownBody($schedule),
            'prepare_3d' => $this->prepareBody($schedule),
            'itinerary_2d' => $this->facts->itinerarySummaryText($schedule),
            'crew_contacts' => $this->crewBody($schedule, $now),
            'trip_faq' => $this->faqBody($schedule),
            'weather_2d' => $this->weatherBody($schedule),
            'pickup_eve' => $this->pickupBody($schedule),
            'departure_soon' => $this->departureBody($schedule),
            'trip_end' => $this->tripEndBody($schedule),
            'photos_expiring' => $this->photosExpiringBody($schedule),
            default => null,
        };
    }

    private function countdownBody(TripSchedule $schedule): string
    {
        $title = $schedule->trip?->title ?? 'ทริปนี้';

        return "⛰️ อีก 7 วันจะได้เจอกันแล้วครับ — {$title}\n"
            ."ออกเดินทาง {$schedule->departureLabelThai()}\n\n"
            .'ระหว่างนี้เปิดแอปกดปุ่มเหนือช่องพิมพ์ ดูกำหนดการ จุดรับ และเบอร์ติดต่อได้เลยครับ '
            .'มีอะไรสงสัยถามในห้องนี้ได้ตลอดครับ ทีมงานอ่านทุกข้อความ 🌿';
    }

    private function prepareBody(TripSchedule $schedule): string
    {
        $items = collect($schedule->trip?->preparations ?? [])
            ->map(fn ($i) => trim((string) $i))
            ->filter()
            ->take(8);

        if ($items->isEmpty()) {
            $items = collect([
                'เสื้อผ้าตามสภาพอากาศ + เสื้อกันฝน',
                'รองเท้าที่ใส่เดินสบาย/กันลื่น',
                'ยาประจำตัวและยาสามัญ',
                'ไฟฉายคาดหัวหรือไฟฉายเล็ก',
                'ขวดน้ำส่วนตัว',
                'บัตรประชาชน (ใช้ตอนเช็คอิน)',
            ]);
        }

        $list = $items->map(fn ($i) => "• {$i}")->implode("\n");

        return "🎒 เหลืออีก 3 วัน เตรียมของกันได้แล้วครับ\n{$list}\n\n"
            .'จัดของแต่เนิ่น ๆ จะได้ไม่ลืมของสำคัญนะครับ ใครมีของเด็ด ๆ แนะนำเพื่อนร่วมทริปได้เลย 😄';
    }

    /**
     * สรุปรายชื่อสตาฟและคนขับพร้อมเบอร์โทร — ข้อมูลที่ลูกค้าถามหามากที่สุดก่อนวันเดินทาง
     *
     * รอข้อมูลได้: ตราบใดที่ยังไม่ครบ (ไม่มีเบอร์สตาฟ หรือรอบที่ใช้รถยังไม่มีเบอร์
     * คนขับ) จะคืน null ให้ job รอบถัดไปลองใหม่ พอแอดมินกรอกครบเมื่อไหร่ ข้อความ
     * จะลงห้องภายในรอบ job ถัดไปทันที ไม่ต้องรอเวลาอื่น
     *
     * ใกล้รถออก (CREW_LAST_CALL_HOURS) แล้วยังไม่ครบ ให้โพสต์เท่าที่มี พร้อมบอกว่า
     * ส่วนที่ขาดจะแจ้งตามในห้องนี้ — มีเบอร์ครึ่งเดียวยังดีกว่าไม่มีเลยตอนตีสี่
     */
    private function crewBody(TripSchedule $schedule, CarbonImmutable $now): ?string
    {
        $schedule->loadMissing(['vehicle.driver']);

        $staff = $this->facts->staff($schedule);
        $driver = $this->facts->driver($schedule);

        // รอบที่บินไปไม่มีรถและคนขับของบริษัท — ครบที่สตาฟอย่างเดียวก็โพสต์ได้
        $needsDriver = $schedule->transport_type !== TripSchedule::TRANSPORT_FLIGHT;

        $driverPhone = trim((string) ($driver['phone'] ?? ''));
        $staffReady = collect($staff)->contains(fn (array $s) => trim((string) ($s['phone'] ?? '')) !== '');
        $driverReady = ! $needsDriver || $driverPhone !== '';

        if (! $staffReady || ! $driverReady) {
            $lastCall = $this->departsAtThai($schedule)?->subHours(self::CREW_LAST_CALL_HOURS);

            // ยังพอรอได้ — ไว้โพสต์ตอนข้อมูลครบ
            if ($lastCall === null || $now->lt($lastCall)) {
                return null;
            }

            // หมดเวลารอแล้วแต่ยังไม่มีอะไรจะบอกเลย ไม่ต้องโพสต์ให้เสียของ
            if ($staff === [] && $driver === null) {
                return null;
            }
        }

        $lines = ['🎽 ทีมงานที่จะไปกับเรารอบนี้ครับ'];
        $lines[] = 'ออกเดินทาง '.$schedule->departureLabelThai();
        $lines[] = '';

        if ($staff !== []) {
            $lines[] = 'สตาฟประจำรอบ';
            foreach ($staff as $member) {
                $phone = $this->phoneLabel(trim((string) ($member['phone'] ?? '')));
                $lines[] = '• '.$member['name']
                    .($phone !== '' ? " — โทร {$phone}" : ' — ทีมงานจะแจ้งเบอร์ให้อีกครั้ง');
            }
        } else {
            $lines[] = 'สตาฟประจำรอบ: '.TripFactsService::PENDING_STAFF;
        }

        if ($needsDriver) {
            $lines[] = '';
            if ($driver !== null) {
                $plate = trim((string) ($schedule->vehicle?->license_plate ?? ''));
                $detail = collect([
                    $driverPhone !== '' ? 'โทร '.$this->phoneLabel($driverPhone) : null,
                    $plate !== '' ? "ทะเบียน {$plate}" : null,
                ])->filter()->implode(' · ');

                $lines[] = '🚐 คนขับ';
                $lines[] = '• '.$driver['name'].($detail !== '' ? " — {$detail}" : '');
            } else {
                $lines[] = '🚐 คนขับ: '.TripFactsService::PENDING_DRIVER;
            }
        }

        $lines[] = '';
        $lines[] = 'แตะที่เบอร์เพื่อโทรได้เลยครับ ถึงจุดรับแล้วไม่เจอรถ '
            .'หรือมีเหตุด่วนก่อนออกเดินทาง โทรหาทีมงานได้ตลอดครับ 🙏';

        return implode("\n", $lines);
    }

    /**
     * คำถามที่พบบ่อยของทริป — ทริปที่ยังไม่ได้เขียน FAQ ไว้จะไม่มีข้อความนี้
     * (ไม่แต่งคำตอบเรื่องนโยบายขึ้นเอง)
     */
    private function faqBody(TripSchedule $schedule): ?string
    {
        $faqs = collect($schedule->trip?->faqs ?? [])
            ->map(function ($faq) {
                $question = trim((string) (is_array($faq) ? ($faq['question'] ?? '') : ''));
                $answer = trim((string) (is_array($faq) ? ($faq['answer'] ?? '') : ''));

                return ($question === '' || $answer === '') ? null : [$question, $answer];
            })
            ->filter()
            ->values();

        if ($faqs->isEmpty()) {
            return null;
        }

        $lines = ['❓ คำถามที่พบบ่อยของทริปนี้'];

        foreach ($faqs->take(self::FAQ_LIMIT) as [$question, $answer]) {
            $lines[] = '';
            $lines[] = "• {$question}";
            $lines[] = '  '.Str::limit(preg_replace('/\s+/u', ' ', $answer), 220);
        }

        $lines[] = '';

        $more = $faqs->count() - self::FAQ_LIMIT;
        if ($more > 0) {
            $lines[] = "ยังมีอีก {$more} คำถามอ่านต่อได้ที่หน้าทริปในแอปครับ";
        }

        $lines[] = 'ถ้ามีคำถามอื่นที่ยังไม่ได้ตอบ พิมพ์ถามในห้องนี้ได้เลยครับ ทีมงานอ่านทุกข้อความ 🌿';

        return implode("\n", $lines);
    }

    /**
     * เบอร์โทรแบบอ่านง่าย 081-222-3333 / 02-123-4567 — เบอร์ที่ผิดรูปคืนค่าเดิมไป
     * (แอปกับเว็บจับรูปแบบขีดคั่นนี้ทำเป็นลิงก์กดโทรได้อยู่แล้ว)
     */
    private function phoneLabel(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6);
        }

        if (strlen($digits) === 9) {
            return substr($digits, 0, 2).'-'.substr($digits, 2, 3).'-'.substr($digits, 5);
        }

        return $phone;
    }

    /**
     * เวลารถออกจริงในเวลาไทย (คอลัมน์เก็บเวลาไทยไว้ในคอลัมน์ชนิด UTC)
     * ไม่ได้กำหนดเวลาไว้ = ถือว่าเช้าตรู่ของวันทริป
     */
    private function departsAtThai(TripSchedule $schedule): ?CarbonImmutable
    {
        if ($schedule->departs_at) {
            return CarbonImmutable::parse($schedule->departs_at->format('Y-m-d H:i:s'), self::TIMEZONE);
        }

        return $schedule->departure_date
            ? CarbonImmutable::parse($schedule->departure_date->toDateString(), self::TIMEZONE)->setTime(5, 30)
            : null;
    }

    /**
     * เวลาของสรุปพรุ่งนี้แต่ละคืน — คืนก่อนวันที่ 2 ไปจนถึงคืนก่อนวันกลับ
     *
     * @return array<string, array{0: ?CarbonImmutable, 1: int}>
     */
    private function dayBriefTimes(?CarbonImmutable $departureDate, ?CarbonImmutable $endDate): array
    {
        $out = [];
        $days = $this->tripDayCount($departureDate, $endDate);

        for ($day = 2; $day <= $days; $day++) {
            $out[self::DAY_BRIEF_PREFIX.$day] = [
                $departureDate->addDays($day - 2)->setTime(self::DAY_BRIEF_HOUR, 0),
                self::DAY_BRIEF_GRACE_HOURS,
            ];
        }

        return $out;
    }

    /** ทริปกี่วัน (นับวันออกเดินทางเป็นวันที่ 1) — ไม่มีวันกลับ = ทริปวันเดียว */
    private function tripDayCount(?CarbonImmutable $departureDate, ?CarbonImmutable $endDate): int
    {
        if ($departureDate === null || $endDate === null || $endDate->lte($departureDate)) {
            return 1;
        }

        return min(self::DAY_BRIEF_MAX_DAYS, (int) round($departureDate->diffInDays($endDate)) + 1);
    }

    /**
     * สรุปพรุ่งนี้ของทริปหลายวัน: นัดรวมพลที่ทีมงานตั้งไว้ แผนของวันนั้น อากาศ และของ
     * ที่ควรเตรียมไว้ตั้งแต่คืนนี้ — ระหว่างทริปคนถามซ้ำทุกคืนว่า "พรุ่งนี้ตื่นกี่โมง
     * ไปไหนบ้าง" ทั้งที่คำตอบอยู่ในกำหนดการอยู่แล้ว
     *
     * ไม่มีอะไรจะบอกเลย (ไม่มีแผนของวันนั้น ไม่มีนัด ไม่มีพยากรณ์) = null ไม่โพสต์ข้อความ
     * ที่มีแต่หัวเรื่อง
     */
    private function dayBriefBody(TripSchedule $schedule, int $day): ?string
    {
        if (! $schedule->departure_date) {
            return null;
        }

        $departureDate = CarbonImmutable::parse($schedule->departure_date->toDateString(), self::TIMEZONE);
        $endDate = $schedule->return_date
            ? CarbonImmutable::parse($schedule->return_date->toDateString(), self::TIMEZONE)
            : $departureDate;
        $total = $this->tripDayCount($departureDate, $endDate);

        if ($day < 2 || $day > $total) {
            return null;
        }

        $date = $departureDate->addDays($day - 1);
        $isLast = $day === $total;

        [$plan, $fromTrip] = $this->dayPlanLines($schedule, $date);
        $meetups = $this->meetupsOn($schedule, $date);
        $forecast = $this->forecastOn($schedule, $date->toDateString());

        if ($plan === [] && $meetups->isEmpty() && $forecast === null) {
            return null;
        }

        $lines = [
            '🌙 สรุปพรุ่งนี้ — '.($isLast ? "วันสุดท้าย (วันที่ {$day} จาก {$total})" : "วันที่ {$day} จาก {$total}")
                .' · '.ThaiDate::full($date),
        ];

        foreach ($meetups as $meetup) {
            $place = $meetup->place ? " ที่{$meetup->place}" : '';
            $lines[] = '⏰ นัดรวมพล '.$meetup->return_at->copy()->timezone(self::TIMEZONE)->format('H:i')." น.{$place}";
        }

        if ($plan !== []) {
            $lines[] = '';
            $lines[] = '📋 แผนพรุ่งนี้';
            if ($fromTrip) {
                $lines[] = '(แผนของทริปนี้ ทีมงานยังไม่ได้ลงกำหนดการเฉพาะรอบ)';
            }
            $lines = array_merge($lines, $plan);
        }

        if ($forecast !== null) {
            $lines[] = '';
            $lines[] = '🌤️ อากาศ: '.$this->forecastLine($forecast);
        }

        $lines[] = '';
        $lines[] = '🎒 เตรียมไว้ตั้งแต่คืนนี้';
        foreach ($this->dayBriefBring($forecast, $meetups, $isLast) as $item) {
            $lines[] = "• {$item}";
        }

        $lines[] = '';
        $lines[] = 'เวลาอาจขยับตามหน้างานและสภาพอากาศ ทีมงานจะแจ้งในห้องนี้ครับ พักผ่อนให้เต็มที่นะครับ 🌿';

        return implode("\n", $lines);
    }

    /**
     * รายการกำหนดการของวันนั้น (ของรอบ หรือแผนของทริปที่ระบุวันไว้) เป็นบรรทัดพร้อมโพสต์
     * รายการที่ไม่ได้ระบุวันข้ามไป — ทริปหลายวันเดาไม่ได้ว่าเป็นของวันไหน
     *
     * @return array{0: array<int, string>, 1: bool} [บรรทัด, มาจากแผนของทริปไหม]
     */
    private function dayPlanLines(TripSchedule $schedule, CarbonImmutable $date): array
    {
        $payload = $this->itinerary->payload($schedule);
        $target = $date->toDateString();

        $items = array_values(array_filter(
            $payload['items'],
            fn (array $item) => trim((string) ($item['item_date'] ?? '')) === $target,
        ));

        if ($items === []) {
            return [[], false];
        }

        $lines = [];

        foreach (array_slice($items, 0, self::DAY_BRIEF_ITEM_LIMIT) as $item) {
            $time = trim((string) ($item['time'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $lines[] = '• '.($time !== '' ? "{$time} น. " : '').$title;

            // บรรทัดแรกของรายละเอียดมักเป็นสิ่งที่ต้องรู้ ("เดินขึ้นชัน 2 ชม. ใส่รองเท้าหุ้มส้น")
            // ฉบับเต็มอยู่ในหน้ากำหนดการ สรุปพรุ่งนี้จึงยกแค่บรรทัดเดียว
            $detail = trim((string) strtok((string) ($item['detail'] ?? ''), "\r\n"));
            if ($detail !== '' && $detail !== $title) {
                $lines[] = '  '.Str::limit(preg_replace('/\s+/u', ' ', $detail), 90);
            }
        }

        $more = count($items) - self::DAY_BRIEF_ITEM_LIMIT;
        if ($more > 0) {
            $lines[] = "ยังมีอีก {$more} รายการ — กดปุ่ม \"กำหนดการ\" เหนือช่องพิมพ์ ดูฉบับเต็มได้ครับ";
        }

        return [$lines, ($payload['source'] ?? '') === 'trip'];
    }

    /**
     * นัดรวมพลล่วงหน้าที่ทีมงานตั้งไว้ในวันนั้น (ยังไม่จบ) เรียงตามเวลา
     *
     * @return Collection<int, ChatRestStop>
     */
    private function meetupsOn(TripSchedule $schedule, CarbonImmutable $date): Collection
    {
        return ChatRestStop::where('schedule_id', $schedule->id)
            ->where('kind', ChatRestStop::KIND_MEETUP)
            ->whereNull('departed_at')
            ->whereBetween('return_at', [$date->startOfDay()->utc(), $date->endOfDay()->utc()])
            ->orderBy('return_at')
            ->get();
    }

    /** "ฝนฟ้าคะนอง · 18–27°C · โอกาสฝน 60%" */
    private function forecastLine(WeatherForecast $forecast): string
    {
        $parts = [];

        if ($forecast->description_th) {
            $parts[] = $forecast->description_th;
        }
        if ($forecast->temp_min !== null && $forecast->temp_max !== null) {
            $parts[] = round((float) $forecast->temp_min).'–'.round((float) $forecast->temp_max).'°C';
        }
        $parts[] = 'โอกาสฝน '.(int) round(((float) ($forecast->pop ?? 0)) * 100).'%';

        return implode(' · ', $parts);
    }

    /**
     * ของที่ควรเตรียมไว้คืนนี้ — คิดจากนัด อากาศ และวันสุดท้าย ไม่ใช่รายการตายตัว
     *
     * @param  Collection<int, ChatRestStop>  $meetups
     * @return array<int, string>
     */
    private function dayBriefBring(?WeatherForecast $forecast, Collection $meetups, bool $isLast): array
    {
        $items = [];

        $earliest = $meetups->first()?->return_at?->copy()->timezone(self::TIMEZONE);
        if ($earliest !== null && $earliest->hour < 6) {
            $items[] = 'ไฟฉายคาดหัว และตั้งนาฬิกาปลุกเผื่อเวลาแต่งตัว — นัดก่อนฟ้าสาง';
        }

        if ($forecast !== null) {
            $pop = (float) ($forecast->pop ?? 0);

            if ($forecast->severity === 'warning' || $pop >= 0.5) {
                $items[] = 'เสื้อกันฝน และถุงกันน้ำใส่โทรศัพท์/ของสำคัญ';
            } elseif ($forecast->severity === 'watch' || $pop >= 0.3) {
                $items[] = 'เสื้อกันฝนติดกระเป๋าไว้';
            }

            if ($forecast->temp_min !== null && (float) $forecast->temp_min < 16) {
                $items[] = 'เสื้อกันหนาว — เช้ามืดอาจเหลือ '.round((float) $forecast->temp_min).'°C';
            }

            if ($forecast->temp_max !== null && (float) $forecast->temp_max >= 33) {
                $items[] = 'น้ำดื่ม หมวก และครีมกันแดด — กลางวันร้อนถึง '.round((float) $forecast->temp_max).'°C';
            }
        }

        if ($isLast) {
            $items[] = 'เก็บของลงกระเป๋าให้พร้อม แล้วเช็คห้องพักอีกรอบก่อนออก จะได้ไม่ลืมของไว้';
        }

        $items[] = 'ชาร์จโทรศัพท์และพาวเวอร์แบงก์ให้เต็ม';

        return $items;
    }

    /**
     * พยากรณ์อากาศ ณ พิกัดของทริปในวันที่ระบุ — ทริปไม่มีพิกัด หรือดึงไม่ได้ = null
     */
    private function forecastOn(TripSchedule $schedule, string $date): ?WeatherForecast
    {
        $trip = $schedule->trip;
        if (! $trip || $trip->latitude === null || $trip->longitude === null) {
            return null;
        }

        try {
            return $this->weatherService->forecastFor((float) $trip->latitude, (float) $trip->longitude, $date);
        } catch (\Throwable $e) {
            Log::warning('TripChatTimeline: ดึงพยากรณ์อากาศไม่สำเร็จ', [
                'schedule_id' => $schedule->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function weatherBody(TripSchedule $schedule): ?string
    {
        if (! $schedule->departure_date) {
            return null;
        }

        $forecast = $this->forecastOn($schedule, $schedule->departure_date->toDateString());

        if (! $forecast) {
            return null;
        }

        $dateLabel = ThaiDate::full($schedule->departure_date);
        $desc = $forecast->description_th ? "{$forecast->description_th} " : '';
        $temp = ($forecast->temp_min !== null && $forecast->temp_max !== null)
            ? 'อุณหภูมิ '.round((float) $forecast->temp_min).'–'.round((float) $forecast->temp_max)."°C\n"
            : '';
        $pop = 'โอกาสฝนตก '.(int) round(((float) ($forecast->pop ?? 0)) * 100)."%\n";

        $advice = match ($forecast->severity) {
            'warning' => 'คาดว่ามีฝน/พายุ เตรียมเสื้อกันฝน ถุงกันน้ำใส่อุปกรณ์ และรองเท้ากันลื่นไปด้วยนะครับ '
                .'ถ้าสภาพอากาศกระทบแผนเดินทาง ทีมงานจะแจ้งในห้องนี้ทันทีครับ',
            'watch' => 'มีโอกาสเจอฝนบ้าง พกเสื้อกันฝนติดกระเป๋าไว้อุ่นใจกว่าครับ',
            default => 'อากาศดูเป็นใจครับ 🌤️ เตรียมของตามเช็คลิสต์ได้เลย',
        };

        return "🌤️ อากาศวันเดินทาง ({$dateLabel})\n{$desc}\n{$temp}{$pop}\n{$advice}";
    }

    private function pickupBody(TripSchedule $schedule): string
    {
        $points = SchedulePickupPoint::where('schedule_id', $schedule->id)
            ->orderBy('sort_order')
            ->get();

        $lines = $points->map(function (SchedulePickupPoint $p) {
            $where = trim((string) ($p->region_label ?: $p->pickup_location));
            $detail = trim((string) $p->pickup_location);
            $time = trim((string) $p->pickup_time);

            $line = '• '.$where;
            if ($detail !== '' && $detail !== $where) {
                $line .= " — {$detail}";
            }
            if ($time !== '') {
                $line .= " เวลา {$time} น.";
            }

            return $line;
        })->implode("\n");

        if ($lines === '') {
            $fallback = trim((string) ($schedule->trip?->departure_point ?? ''));
            $lines = $fallback !== ''
                ? "• {$fallback}"
                : '• ทีมงานจะแจ้งจุดรับและเวลาให้ในห้องนี้อีกครั้งครับ';
        }

        $bring = $schedule->trip?->checkinBringNote();
        $arrive = $bring !== null
            ? "รบกวนมาถึงก่อนเวลานัด 10–15 นาที และพก{$bring}ไปด้วยนะครับ (ใช้ตอนเช็คอิน)"
            : 'รบกวนมาถึงก่อนเวลานัด 10–15 นาทีนะครับ';

        return "🚐 พรุ่งนี้เดินทางแล้วครับ — สรุปจุดรับและเวลา\n{$lines}\n\n"
            .$arrive."\n"
            .'คืนนี้พักผ่อนให้เต็มที่ ถ้าติดขัดอะไรโทรหาทีมงานได้จากแถบด้านบนห้องแชทเลยครับ 🙏';
    }

    private function departureBody(TripSchedule $schedule): string
    {
        $timeLabel = $schedule->departs_at
            ? $schedule->departs_at->format('H:i').' น.'
            : 'ตามเวลานัดหมาย';

        return "🚌 วันนี้เดินทางแล้วครับ! รถออกเวลา {$timeLabel}\n"
            ."• เปิดหน้า “วันเดินทาง” ในแอปเพื่อดูตำแหน่งรถแบบเรียลไทม์และ QR เช็คอิน\n"
            ."• ถึงจุดรับแล้วทักในห้องนี้ได้เลย ทีมงานจะได้รู้ว่าใครถึงแล้วบ้าง\n"
            .'• ถ้ามาไม่ทันหรือมีเหตุฉุกเฉิน โทรหาทีมงานได้ทันทีจากแถบด้านบนครับ';
    }

    private function tripEndBody(TripSchedule $schedule): string
    {
        $title = $schedule->trip?->title ?? 'ทริปนี้';
        $days = PurgeEndedTripChatsJob::DELETE_AFTER_DAYS;

        $lines = [];

        // รูปของทีมงานไม่ได้ลงในห้องแชท — บอกที่อยู่ของมันตั้งแต่วันจบทริป ไม่งั้น
        // ลูกค้าจะรอรูปในห้องนี้จนถึงวันที่ห้องถูกลบ
        if ($ig = $this->instagramHandle()) {
            $lines[] = "• รูปจากกล้องทีมงานจะทยอยลงที่ไอจี {$ig} กดติดตามไว้ไม่พลาดนะครับ 📸";
        }

        // ชวนแชร์รูปพร้อมบอกอายุของห้องไปด้วย — เคยชวนลอย ๆ แล้วรูปหายไปใน 3 วัน
        $lines[] = "• รูปสวย ๆ ของทุกคนแชร์ในห้องนี้ได้เลยครับ — ห้องแชทจะปิดใน {$days} วัน "
            .'อยากให้รูปอยู่ยาว ๆ ลงในฟีดทริปในแอปได้เหมือนกันครับ';
        $lines[] = '• รีวิวทริปในแอปเปิดให้เขียนแล้ววันนี้ ทุกความเห็นมีค่ากับทีมงานมากครับ';

        return "🌿 จบ{$title}อย่างสวยงามครับ ขอบคุณทุกท่านที่ร่วมเดินทางกับเรา\n"
            .implode("\n", $lines)."\n"
            .'แล้วเจอกันใหม่ทริปหน้านะครับ 🙏';
    }

    /**
     * เตือนก่อนห้องแชทถูกลบ — ข้อความต่างกันตามว่าในห้องมีรูปให้เซฟจริงไหม
     *
     * ห้องที่ยังไม่มีใครส่งรูปเลยเคยได้ข้อความ "รูปทั้งหมดจะถูกลบ" เหมือนกัน
     * ลูกค้าอ่านแล้วเข้าใจว่ารูปทริปมาแล้วและกำลังจะหาย ทั้งที่ทีมงานยังไม่ได้
     * ลงรูปสักใบ ข้อความจึงพูดถึงรูปเฉพาะตอนที่ในห้องมีรูปอยู่จริง
     *
     * ห้องที่ไม่มีใครพิมพ์อะไรเลยข้ามไปเลย (คืน null) — ข่าวว่าห้องร้างกำลังจะถูก
     * ลบไม่คุ้มกับ badge ยังไม่อ่านที่เด้งขึ้นมา
     */
    private function photosExpiringBody(TripSchedule $schedule): ?string
    {
        if (! $this->roomHasHumanMessages($schedule)) {
            return null;
        }

        $follow = ($ig = $this->instagramHandle())
            ? "รูปจากกล้องทีมงานจะทยอยลงที่ไอจี {$ig} กดติดตามไว้ไม่พลาดนะครับ 📸"
            : null;

        if (! $this->roomHasPhotos($schedule)) {
            return "🌿 ห้องแชทของทริปนี้จะถูกปิดและลบข้อความทั้งหมดอัตโนมัติในเช้าวันพรุ่งนี้นะครับ\n"
                .'ถ้ามีอะไรอยากถามหรืออยากบอกทีมงาน ทักในห้องนี้ได้ถึงคืนนี้เลยครับ '
                .'หลังจากนั้นทักหาทีมงานได้ที่ศูนย์ช่วยเหลือในแอปเหมือนเดิมครับ 🙏'
                .($follow ? "\n{$follow}" : '');
        }

        return "📸 ห้องแชทนี้พร้อมรูปที่แชร์กันในห้องจะถูกลบอัตโนมัติในเช้าวันพรุ่งนี้นะครับ\n"
            ."ใครยังไม่ได้เก็บรูป แตะที่รูปในห้องแล้วกดบันทึกลงเครื่องได้เลยครับ\n"
            .'อยากเก็บไว้ให้เพื่อนร่วมทริปดูต่อ ลงในฟีดทริปในแอปได้เหมือนกันครับ'
            .($follow ? "\n{$follow}" : ' 🌿');
    }

    /** ในห้องมีรูปที่ยังไม่ถูกลบอยู่จริงไหม (รูปของห้องแชทเท่านั้น ไม่นับอัลบั้มทริป) */
    private function roomHasPhotos(TripSchedule $schedule): bool
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->where('is_deleted', false)
            ->exists();
    }

    /** มีคนจริง ๆ (ลูกค้า/ทีมงาน) พิมพ์ในห้องนี้ไหม — ข้อความระบบมี user_id เป็น null */
    private function roomHasHumanMessages(TripSchedule $schedule): bool
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->whereNotNull('user_id')
            ->where('is_deleted', false)
            ->exists();
    }

    /**
     * ชื่อไอจีของแบรนด์ในรูป @handle — อ่านจากลิงก์เดียวกับที่เว็บใช้
     * (config/company.php) จะได้ไม่ต้องพิมพ์ชื่อเพจซ้ำไว้ในข้อความ
     */
    private function instagramHandle(): ?string
    {
        $url = trim((string) config('company.social.instagram'));
        if ($url === '') {
            return null;
        }

        $handle = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $handle !== '' ? '@'.$handle : null;
    }
}
