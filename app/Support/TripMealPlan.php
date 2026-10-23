<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * มื้ออาหารของทริป — ที่เดียวที่รู้รูปร่างของ `trips.meal_plan`
 *
 * ลูกค้าถามสามข้อเสมอ: กี่มื้อ มื้อไหน และต้องจ่ายเองไหม ข้อความลอย ๆ ใน
 * "รวมในราคา" ตอบได้แค่ข้อแรกและนับให้ใครไม่ได้ จึงเก็บเป็นตารางวัน × มื้อ
 * แล้วให้ทุกหน้าจอ (หน้าทริป หน้าสรุปก่อนจ่าย ใบเดินทาง แชท ผู้ช่วย) อ่านคำ
 * ชุดเดียวจากที่นี่ ไม่ใช่ต่างคนต่างนับ
 *
 * ที่เก็บ: {days: [{breakfast: {status, note}, lunch: …, dinner: …}, …], note}
 * null = แอดมินยังไม่ได้ระบุ (หน้าจอซ่อนทั้งก้อน) ต่างจาก "ระบุแล้วว่าไม่มีอาหาร"
 */
class TripMealPlan
{
    /** รวมในราคา — ทีมงานจัดให้ */
    public const INCLUDED = 'included';

    /** มีแวะกินแต่ลูกค้าจ่ายเอง เช่น จอดร้านระหว่างทาง */
    public const SELF_PAY = 'self';

    /** ไม่มีมื้อนี้ในทริป เช่น ยังเดินทางอยู่ */
    public const NONE = 'none';

    public const STATUSES = [self::INCLUDED, self::SELF_PAY, self::NONE];

    /** ลำดับมื้อในหนึ่งวัน — ทุกหน้าจอวาดตามลำดับนี้ */
    public const MEALS = [
        'breakfast' => 'เช้า',
        'lunch' => 'กลางวัน',
        'dinner' => 'เย็น',
    ];

    public const STATUS_LABELS = [
        self::INCLUDED => 'รวมในราคา',
        self::SELF_PAY => 'จ่ายเอง',
        self::NONE => 'ไม่มี',
    ];

    public const MAX_DAYS = 30;

    /** เมนู/ร้านสั้น ๆ ต่อมื้อ เช่น "หมูกระทะ" — ยาวกว่านี้ไปเขียนในหมายเหตุรวม */
    public const NOTE_MAX = 80;

    public const GENERAL_NOTE_MAX = 300;

    /**
     * จัดระเบียบค่าดิบให้อยู่ในรูปที่เก็บ — null เมื่อไม่มีวันให้ระบุเลย
     *
     * สถานะที่ไม่รู้จักกลายเป็น "ไม่มี" ไม่ใช่ "รวมในราคา" — บอกลูกค้าว่ามีอาหาร
     * ทั้งที่ไม่มี แย่กว่าบอกน้อยไป และวันที่เกินจำนวนวันของทริปถูกตัดทิ้ง
     * (แอดมินลดจำนวนวันแล้ววันสุดท้ายต้องไม่ค้างอยู่บนหน้าทริป)
     *
     * @return array{days: array<int, array<string, array{status: string, note: string}>>, note: string}|null
     */
    public static function normalize(mixed $raw, ?int $maxDays = null): ?array
    {
        if (! is_array($raw) || ! is_array($raw['days'] ?? null)) {
            return null;
        }

        $limit = min(self::MAX_DAYS, max(1, $maxDays ?? self::MAX_DAYS));
        $days = [];

        foreach (array_slice(array_values($raw['days']), 0, $limit) as $rawDay) {
            $day = [];
            foreach (array_keys(self::MEALS) as $meal) {
                $cell = is_array($rawDay) ? ($rawDay[$meal] ?? null) : null;
                $status = is_array($cell) ? ($cell['status'] ?? null) : null;

                $day[$meal] = [
                    'status' => in_array($status, self::STATUSES, true) ? $status : self::NONE,
                    'note' => self::clip(is_array($cell) ? ($cell['note'] ?? '') : '', self::NOTE_MAX),
                ];
            }
            $days[] = $day;
        }

        if ($days === []) {
            return null;
        }

        return [
            'days' => $days,
            'note' => self::clip($raw['note'] ?? '', self::GENERAL_NOTE_MAX),
        ];
    }

    /**
     * รูปที่หน้าจอลูกค้าวาด — null เมื่อทริปยังไม่ได้ระบุมื้ออาหาร
     *
     * ใส่ $startDate (วันออกเดินทางของรอบ) เมื่อรู้รอบแล้ว เพื่อให้แต่ละวันมีวันที่จริง
     * ติดไปด้วย ส่วนหน้าทริปที่ยังไม่ได้เลือกรอบได้แค่ "วันที่ 1, 2, …"
     *
     * @return array<string, mixed>|null
     */
    public static function present(mixed $raw, ?int $maxDays = null, ?CarbonInterface $startDate = null): ?array
    {
        $plan = self::normalize($raw, $maxDays);
        if ($plan === null) {
            return null;
        }

        $days = [];
        foreach ($plan['days'] as $index => $day) {
            $date = $startDate?->copy()->startOfDay()->addDays($index);
            $meals = [];
            foreach (self::MEALS as $key => $label) {
                $status = $day[$key]['status'];
                $meals[] = [
                    'key' => $key,
                    'label' => $label,
                    'status' => $status,
                    'status_label' => self::STATUS_LABELS[$status],
                    'note' => $day[$key]['note'] !== '' ? $day[$key]['note'] : null,
                ];
            }

            $days[] = [
                'day' => $index + 1,
                'label' => 'วันที่ '.($index + 1),
                'date' => $date?->toDateString(),
                'date_label' => $date ? ThaiDate::short($date) : null,
                'meals' => $meals,
                'line' => self::dayLine($meals),
            ];
        }

        [$included, $selfPay] = self::counts($plan);

        return [
            'days' => $days,
            'included_count' => $included,
            'self_count' => $selfPay,
            'label' => self::label($included),
            'summary' => self::summary($included, $selfPay),
            'note' => $plan['note'] !== '' ? $plan['note'] : null,
        ];
    }

    /**
     * ข้อความหลายบรรทัดสำหรับที่ที่วาดตารางไม่ได้ — ห้องแชท อีเมล ผู้ช่วย AI
     * null เมื่อทริปยังไม่ได้ระบุ (ผู้เรียกต้องไม่ตอบแทนการเดา)
     */
    public static function text(mixed $raw, ?int $maxDays = null, ?CarbonInterface $startDate = null): ?string
    {
        $meals = self::present($raw, $maxDays, $startDate);
        if ($meals === null) {
            return null;
        }

        $lines = ['🍽️ มื้ออาหาร: '.$meals['summary']];
        foreach ($meals['days'] as $day) {
            $head = $day['label'].($day['date_label'] && count($meals['days']) > 1 ? ' ('.$day['date_label'].')' : '');
            $lines[] = '• '.$head.': '.$day['line'];
        }
        if ($meals['note']) {
            $lines[] = 'หมายเหตุ: '.$meals['note'];
        }

        return implode("\n", $lines);
    }

    /**
     * นับมื้อที่รวมในราคาและมื้อที่จ่ายเอง
     *
     * @return array{0: int, 1: int}
     */
    public static function counts(?array $plan): array
    {
        $included = 0;
        $selfPay = 0;
        foreach ($plan['days'] ?? [] as $day) {
            foreach (array_keys(self::MEALS) as $meal) {
                $status = $day[$meal]['status'] ?? self::NONE;
                $included += $status === self::INCLUDED ? 1 : 0;
                $selfPay += $status === self::SELF_PAY ? 1 : 0;
            }
        }

        return [$included, $selfPay];
    }

    /** ป้ายสั้นข้างจำนวนวัน — "อาหาร 5 มื้อ" */
    public static function label(int $included): string
    {
        return $included > 0 ? "อาหาร {$included} มื้อ" : 'ไม่รวมอาหาร';
    }

    /** ประโยคเต็มที่ตอบทั้ง "กี่มื้อ" และ "ต้องจ่ายเองไหม" ในบรรทัดเดียว */
    public static function summary(int $included, int $selfPay): string
    {
        $head = $included > 0 ? "รวมอาหาร {$included} มื้อ" : 'ไม่รวมอาหาร';

        return $selfPay > 0 ? "{$head} · จ่ายเอง {$selfPay} มื้อ" : $head;
    }

    /**
     * เดาตารางมื้ออาหารจากกำหนดการของทริป — ใช้เติมค่าเริ่มต้นให้แอดมินตรวจ
     * ก่อนบันทึกเท่านั้น ห้ามเอาผลนี้ไปโชว์ลูกค้าตรง ๆ
     *
     * จับเฉพาะคำที่บอกมื้อชัด ๆ ("อาหารเช้า", "มื้อเย็น", "ข้าวกลางวัน",
     * "อาหารเช้าและกลางวัน") ไม่จับ "ตอนเช้า" ลอย ๆ บรรทัดที่มีคำว่า "งด" ข้ามทิ้ง
     * ("งดอาหารเย็นก่อนดำน้ำ" ไม่ใช่มื้อ) ส่วนบรรทัดที่บอกว่าอิสระ/จ่ายเอง
     * เป็น "จ่ายเอง" มื้อเดียวกันเจอทั้งสองแบบ ถือว่ารวมในราคา
     *
     * @return array{meal_plan: array<string, mixed>, found: int}
     */
    public static function guess(mixed $itinerary, int $durationDays): array
    {
        $durationDays = min(self::MAX_DAYS, max(1, $durationDays));
        $grid = array_fill(0, $durationDays, array_fill_keys(array_keys(self::MEALS), self::NONE));

        foreach (self::itineraryEntries($itinerary) as [$day, $text]) {
            if ($day === null) {
                // ไม่ระบุวัน — เชื่อได้เฉพาะทริปวันเดียว
                if ($durationDays !== 1) {
                    continue;
                }
                $day = 1;
            }
            if ($day < 1 || $day > $durationDays) {
                continue;
            }

            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $compact = (string) preg_replace('/\s+/u', '', mb_strtolower($line));
                if ($compact === '' || str_contains($compact, 'งด')) {
                    continue;
                }

                $status = preg_match('/อิสระ|ตามอัธยาศัย|จ่ายเอง|ชำระเอง|ออกเอง|ไม่รวมในราคา|ไม่รวมค่าอาหาร|ค่าใช้จ่ายเอง|ค่าใช้จ่ายส่วนตัว/u', $compact)
                    ? self::SELF_PAY
                    : self::INCLUDED;

                foreach (self::mealsMentioned($compact) as $meal) {
                    $current = $grid[$day - 1][$meal];
                    if ($current === self::INCLUDED) {
                        continue;
                    }
                    $grid[$day - 1][$meal] = $status;
                }
            }
        }

        $found = 0;
        $days = [];
        foreach ($grid as $day) {
            $row = [];
            foreach ($day as $meal => $status) {
                $row[$meal] = ['status' => $status, 'note' => ''];
                $found += $status !== self::NONE ? 1 : 0;
            }
            $days[] = $row;
        }

        return [
            'meal_plan' => ['days' => $days, 'note' => ''],
            'found' => $found,
        ];
    }

    /**
     * มื้อที่บรรทัดนี้พูดถึง (ข้อความถูกตัดช่องว่างและทำเป็นตัวเล็กแล้ว)
     *
     * @return array<int, string>
     */
    private static function mealsMentioned(string $compact): array
    {
        $word = '(?:เช้า|กลางวัน|เที่ยง|เย็น|ค่ำ)';
        $found = [];

        // "อาหารเช้า", "มื้อเย็น", "ข้าวกลางวัน" และรายการต่อท้าย "อาหารเช้าและกลางวัน"
        if (preg_match_all(
            '/(?:อาหาร|มื้อ|ข้าว)(?:มื้อ)?('.$word.'(?:(?:และ|กับ|[-–\/,+&])(?:มื้อ)?'.$word.')*)/u',
            $compact,
            $matches,
        )) {
            foreach ($matches[1] as $chain) {
                preg_match_all('/'.$word.'/u', $chain, $words);
                foreach ($words[0] as $w) {
                    $found[] = match ($w) {
                        'เช้า' => 'breakfast',
                        'กลางวัน', 'เที่ยง' => 'lunch',
                        default => 'dinner',
                    };
                }
            }
        }

        foreach (['breakfast' => 'breakfast', 'lunch' => 'lunch', 'dinner' => 'dinner'] as $english => $meal) {
            if (str_contains($compact, $english)) {
                $found[] = $meal;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * แตกกำหนดการของทริป (ทั้งแบบมีภาค และแบบเก่าที่เป็นรายการแบน) เป็นคู่ [วัน, ข้อความ]
     *
     * @return array<int, array{0: int|null, 1: string}>
     */
    private static function itineraryEntries(mixed $itinerary): array
    {
        if (! is_array($itinerary)) {
            return [];
        }

        $items = [];
        foreach ($itinerary as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (is_array($entry['items'] ?? null)) {
                foreach ($entry['items'] as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            } else {
                $items[] = $entry;
            }
        }

        return array_map(function (array $item) {
            $day = is_numeric($item['day'] ?? null) ? (int) $item['day'] : null;
            $text = trim((string) ($item['title'] ?? ''))."\n".trim((string) ($item['description'] ?? ''));

            return [$day, $text];
        }, $items);
    }

    /**
     * หนึ่งวันในรูปข้อความ — "เช้า · กลางวัน – ข้าวกล่อง · เย็น (จ่ายเอง)"
     *
     * @param  array<int, array<string, mixed>>  $meals
     */
    private static function dayLine(array $meals): string
    {
        $parts = [];
        foreach ($meals as $meal) {
            if ($meal['status'] === self::NONE) {
                continue;
            }
            $part = $meal['label'];
            if ($meal['note']) {
                $part .= ' – '.$meal['note'];
            }
            if ($meal['status'] === self::SELF_PAY) {
                $part .= ' (จ่ายเอง)';
            }
            $parts[] = $part;
        }

        return $parts === [] ? 'ไม่มีมื้ออาหาร' : implode(' · ', $parts);
    }

    private static function clip(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';

        return mb_substr($text, 0, $max);
    }
}
