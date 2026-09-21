<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * ไฟล์ปฏิทิน (.ics) ของใบเดินทาง — "เพิ่มลงปฏิทิน" ที่ลูกค้ากดจากหน้า /t/{token}
 *
 * ทำไมต้องมี ทั้งที่ใบเดินทางบอกวันเวลาอยู่แล้ว: ใบเดินทางเป็นของที่ลูกค้าต้อง
 * *เปิด* ถึงจะเห็น ส่วนปฏิทินเป็นของที่มา *หาลูกค้าเอง* — และคนที่พลาดรถส่วนใหญ่
 * ไม่ได้ไม่รู้ว่าไปวันไหน แต่ลืมว่าต้องออกจากบ้านกี่โมง เสียงเตือนล่วงหน้าหนึ่ง
 * ชั่วโมงจึงเป็นของที่มีค่ากว่าตัวนัดเองด้วยซ้ำ
 *
 * วาดจาก payload ของ TripBriefService ก้อนเดียวกับหน้าเว็บและอีเมล — ห้ามไปอ่าน
 * โมเดลเองเพิ่ม ไม่งั้นวันเวลาในปฏิทินจะมีโอกาสไม่ตรงกับที่ลูกค้าเห็นบนหน้าจอ
 *
 * เวลาเขียนเป็น UTC (ลงท้ายด้วย Z) ทั้งหมดโดยตั้งใจ: ไฟล์จะได้ไม่ต้องแบก
 * VTIMEZONE และไม่ต้องหวังว่าปลายทางรู้จัก Asia/Bangkok — เวลาไทยไม่มี DST
 * การแปลงจึงตรงเป๊ะเสมอ
 */
class TripBriefCalendar
{
    private const TIMEZONE = 'Asia/Bangkok';

    /** ชื่อไฟล์ที่ลูกค้าเห็นตอนกดดาวน์โหลด */
    public static function filename(array $brief): string
    {
        return ($brief['booking']['ref'] ?? 'trip').'.ics';
    }

    public static function build(array $brief): string
    {
        $when = $brief['when'] ?? [];
        $title = $brief['trip']['title'] ?? 'ทริปลุยเลเขา';
        $ref = $brief['booking']['ref'] ?? 'booking';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//luilaykhao//trip-brief//TH',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        $timed = self::timedEvent($brief, $when, $title, $ref);
        $lines = array_merge($lines, $timed);

        // นัดทั้งวันเพิ่มอีกใบเมื่อทริปกินเวลาหลายวัน (ให้เห็นเป็นแถบยาวในปฏิทินว่า
        // ช่วงนี้ไม่ว่าง) หรือเมื่อไม่มีเวลาออกเดินทางให้ตั้งนัดแบบมีเวลาได้เลย
        $multiDay = ($when['end_date'] ?? null) && $when['end_date'] > ($when['start_date'] ?? '');

        if ($multiDay || $timed === []) {
            $lines = array_merge($lines, self::allDayEvent($brief, $when, $title, $ref, $timed !== []));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * นัดแบบมีเวลา — เวลาที่ลูกค้าต้องไปยืนรออยู่จริง ไม่ใช่เวลาที่รถออกจากอู่
     *
     * ลำดับความสำคัญ: เวลานัดพบ (รอบบิน) → เวลาที่จุดขึ้นรถของลูกค้าเอง →
     * เวลารถออก รอบที่ไม่เคยตั้งเวลาไว้เลยจะไม่มีนัดแบบนี้ ดีกว่าเดาเวลาให้ลูกค้า
     *
     * @return array<int, string>
     */
    private static function timedEvent(array $brief, array $when, string $title, string $ref): array
    {
        $date = $when['departs_on_date'] ?? $when['start_date'] ?? null;
        $meetup = $brief['meetup'] ?? null;
        $point = $brief['pickup']['points'][0]['point'] ?? null;

        $time = $meetup['time_label']
            ?? (isset($point['time']) ? substr((string) $point['time'], 0, 5) : null)
            ?? $when['time_label']
            ?? null;

        if (! $date || ! $time) {
            return [];
        }

        $start = Carbon::parse($date.' '.$time, self::TIMEZONE);

        $where = $meetup['point'] ?? $point['location'] ?? $brief['trip']['location'] ?? '';
        $summary = $meetup
            ? 'นัดพบ · '.$title
            : 'ออกเดินทาง · '.$title;

        return [
            'BEGIN:VEVENT',
            'UID:'.$ref.'-departure@luilaykhao.com',
            'DTSTAMP:'.self::utc(Carbon::now()),
            'DTSTART:'.self::utc($start),
            // หนึ่งชั่วโมงคือช่วง "ต้องอยู่ตรงนั้นแล้ว" ไม่ใช่ความยาวของทริป
            'DTEND:'.self::utc($start->copy()->addHour()),
            'SUMMARY:'.self::esc($summary),
            'LOCATION:'.self::esc($where),
            'DESCRIPTION:'.self::esc(self::description($brief)),
            'URL:'.self::esc($brief['links']['brief'] ?? ''),
            ...self::alarm('-PT1H', 'อีก 1 ชั่วโมงถึงเวลานัด'),
            ...self::alarm('-PT12H', 'พรุ่งนี้เดินทางแล้ว เตรียมของให้พร้อมนะครับ'),
            'END:VEVENT',
        ];
    }

    /**
     * นัดทั้งวันครอบช่วงทริป — DTEND ของนัดทั้งวันเป็นแบบไม่นับวันสุดท้าย
     * จึงต้องบวกอีกหนึ่งวันเสมอ ไม่งั้นวันกลับจะหายไปจากปฏิทิน
     *
     * @return array<int, string>
     */
    private static function allDayEvent(array $brief, array $when, string $title, string $ref, bool $hasTimedEvent): array
    {
        $start = $when['start_date'] ?? null;

        if (! $start) {
            return [];
        }

        $end = Carbon::parse($when['end_date'] ?: $start, self::TIMEZONE)->addDay();

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$ref.'-trip@luilaykhao.com',
            'DTSTAMP:'.self::utc(Carbon::now()),
            'DTSTART;VALUE=DATE:'.Carbon::parse($start, self::TIMEZONE)->format('Ymd'),
            'DTEND;VALUE=DATE:'.$end->format('Ymd'),
            'SUMMARY:'.self::esc($title),
            'LOCATION:'.self::esc($brief['trip']['location'] ?? ''),
            'DESCRIPTION:'.self::esc(self::description($brief)),
            'URL:'.self::esc($brief['links']['brief'] ?? ''),
            'TRANSP:TRANSPARENT',
        ];

        // เตือนล่วงหน้าเฉพาะตอนไม่มีนัดแบบมีเวลา — ไม่งั้นลูกค้าโดนเตือนสองเด้ง
        if (! $hasTimedEvent) {
            $lines = array_merge($lines, self::alarm('-PT12H', 'พรุ่งนี้เดินทางแล้ว เตรียมของให้พร้อมนะครับ'));
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private static function alarm(string $trigger, string $text): array
    {
        return [
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'TRIGGER:'.$trigger,
            'DESCRIPTION:'.self::esc($text),
            'END:VALARM',
        ];
    }

    private static function description(array $brief): string
    {
        $parts = ['เลขที่จอง '.($brief['booking']['ref'] ?? '')];

        if ($phone = $brief['crew'][0]['phone'] ?? null) {
            $parts[] = 'ทีมงาน '.($brief['crew'][0]['name'] ?? '').' '.$phone;
        }

        if ($url = $brief['links']['brief'] ?? null) {
            $parts[] = 'ใบเดินทาง: '.$url;
        }

        return implode("\n", $parts);
    }

    private static function utc(Carbon $moment): string
    {
        return $moment->copy()->utc()->format('Ymd\THis\Z');
    }

    /** อักขระที่ RFC 5545 ถือว่ามีความหมายในตัวเอง ต้องหนีให้หมดก่อนเขียนลงบรรทัด */
    private static function esc(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\\,', '\\n', '\\n'],
            $value
        );
    }

    /**
     * พับบรรทัดที่ยาวเกิน 75 octet ตามสเปก — ตัดตามขอบตัวอักษร UTF-8 ไม่ใช่ตาม
     * ไบต์ดิบ ไม่งั้นสระลอยกลางไบต์แล้วปฏิทินอ่านภาษาไทยเป็นขยะทั้งบรรทัด
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $chunk = '';

        foreach (mb_str_split($line) as $char) {
            // เผื่อที่ให้ช่องว่างนำหน้าของบรรทัดต่อไว้ล่วงหน้าแล้ว
            if (strlen($chunk) + strlen($char) > 73) {
                $out .= ($out === '' ? '' : "\r\n ").$chunk;
                $chunk = '';
            }

            $chunk .= $char;
        }

        return $out.($out === '' ? '' : "\r\n ").$chunk;
    }
}
