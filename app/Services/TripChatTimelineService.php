<?php

namespace App\Services;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ข้อความอัตโนมัติในห้องแชทตามไทม์ไลน์ของทริป
 *
 * ห้องแชทเดิมเงียบจนกว่าจะมีคนพิมพ์ ทั้งที่ช่วงก่อนเดินทางคือช่วงที่ลูกค้าอยาก
 * รู้มากที่สุด บริการนี้จึงโพสต์ข้อความระบบตามจังหวะของทริป (นับถอยหลัง →
 * เตรียมของ → ทีมงาน+คนขับพร้อมเบอร์โทร → คำถามที่พบบ่อย → อากาศ →
 * จุดรับคืนก่อนเดินทาง → เช้าวันเดินทาง → ปิดทริป → เตือนเซฟรูปก่อนห้องถูกลบ)
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

    public function __construct(
        private ChatService $chatService,
        private WeatherService $weatherService,
        private TripFactsService $facts,
    ) {}

    /**
     * คีย์ทั้งหมดตามลำดับเวลา — ใช้ในเทสและเวลาดีบัก
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return [
            'countdown_7d',
            'prepare_3d',
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
        $existing = ChatMessage::where('schedule_id', $schedule->id)
            ->whereNotNull('system_key')
            ->pluck('system_key')
            ->all();

        foreach ($this->due($schedule, $now) as $key) {
            if (in_array($key, $existing, true)) {
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

        $crewDueAt = $departureDate?->subDays(2)->setTime(10, 0);
        $faqDueAt = $departureDate?->subDays(2)->setTime(10, 30);

        return [
            'countdown_7d' => [$departureDate?->subDays(7)->setTime(9, 0), self::DEFAULT_GRACE_HOURS],
            'prepare_3d' => [$departureDate?->subDays(3)->setTime(19, 0), self::DEFAULT_GRACE_HOURS],
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
        ];
    }

    /**
     * เนื้อข้อความของแต่ละคีย์ — null = ยังไม่มีข้อมูลพอ ให้ข้ามไปก่อน
     */
    private function body(string $key, TripSchedule $schedule, CarbonImmutable $now): ?string
    {
        return match ($key) {
            'countdown_7d' => $this->countdownBody($schedule),
            'prepare_3d' => $this->prepareBody($schedule),
            'crew_contacts' => $this->crewBody($schedule, $now),
            'trip_faq' => $this->faqBody($schedule),
            'weather_2d' => $this->weatherBody($schedule),
            'pickup_eve' => $this->pickupBody($schedule),
            'departure_soon' => $this->departureBody($schedule),
            'trip_end' => $this->tripEndBody($schedule),
            'photos_expiring' => $this->photosExpiringBody(),
            default => null,
        };
    }

    private function countdownBody(TripSchedule $schedule): string
    {
        $title = $schedule->trip?->title ?? 'ทริปนี้';

        return "⛰️ อีก 7 วันจะได้เจอกันแล้วครับ — {$title}\n"
            ."ออกเดินทาง {$schedule->departureLabelThai()}\n\n"
            .'ระหว่างนี้ดูกำหนดการ จุดรับ และอากาศได้จากปุ่มข้อมูลห้อง (มุมขวาบน) '
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

    private function weatherBody(TripSchedule $schedule): ?string
    {
        $trip = $schedule->trip;
        if (! $trip || $trip->latitude === null || $trip->longitude === null || ! $schedule->departure_date) {
            return null;
        }

        try {
            $forecast = $this->weatherService->forecastFor(
                (float) $trip->latitude,
                (float) $trip->longitude,
                $schedule->departure_date->toDateString(),
            );
        } catch (\Throwable $e) {
            Log::warning('TripChatTimeline: ดึงพยากรณ์อากาศไม่สำเร็จ', [
                'schedule_id' => $schedule->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

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

        return "🚐 พรุ่งนี้เดินทางแล้วครับ — สรุปจุดรับและเวลา\n{$lines}\n\n"
            ."รบกวนมาถึงก่อนเวลานัด 10–15 นาที และพกบัตรประชาชนไปด้วยนะครับ (ใช้ตอนเช็คอิน)\n"
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

        return "🌿 จบ{$title}อย่างสวยงามครับ ขอบคุณทุกท่านที่ร่วมเดินทางกับเรา\n"
            ."• รูปสวย ๆ แชร์ในห้องนี้ให้เพื่อนร่วมทริปได้เลยครับ\n"
            ."• รีวิวทริปในแอปเปิดให้เขียนแล้ววันนี้ ทุกความเห็นมีค่ากับทีมงานมากครับ\n"
            .'แล้วเจอกันใหม่ทริปหน้านะครับ 🙏';
    }

    private function photosExpiringBody(): string
    {
        return "📸 ห้องแชทนี้พร้อมรูปทั้งหมดจะถูกลบอัตโนมัติในเช้าวันพรุ่งนี้นะครับ\n"
            ."ใครยังไม่ได้เก็บรูป แตะที่รูปแล้วกดบันทึกลงเครื่องได้เลยครับ\n"
            .'อยากเก็บไว้ให้เพื่อนร่วมทริปดูต่อ ลงในฟีดทริปในแอปได้เหมือนกันครับ 🌿';
    }
}
