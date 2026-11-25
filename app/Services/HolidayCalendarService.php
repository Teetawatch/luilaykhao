<?php

namespace App\Services;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ดึงวันหยุดราชการไทยจากปฏิทินสาธารณะของ Google ลงตาราง public_holidays
 *
 * ใช้สองไฟล์ ICS (ไทย + อังกฤษ) จับคู่กันด้วย UID เดียวกัน — ฉบับอังกฤษบอกชนิด
 * ("Public holiday" / "Observance") ได้ชัด ส่วนฉบับไทยให้ชื่อทางการ เก็บเฉพาะวันหยุด
 * นักขัตฤกษ์ ตัดวันสำคัญที่ไม่ได้หยุด (วาเลนไทน์ ตรุษจีน คริสต์มาส) ทิ้ง
 *
 * ปลอดภัยเมื่อดึงซ้ำ: อัปเดตชื่อ/วันที่ของแถวเดิมตาม UID แต่ไม่แตะ is_active ที่แอดมิน
 * ปิดไว้ และไม่แตะแถวที่แอดมินเพิ่มเอง วันหยุดที่ Google เอาออก (เลื่อน/ยกเลิก) ถูกลบ
 * เฉพาะในช่วงปีที่ไฟล์ครอบคลุม ไฟล์ที่ดูผิดปกติ (วันหยุดน้อยผิดตา) ไม่ลบอะไรเลย
 */
class HolidayCalendarService
{
    /** ปีหนึ่งมีวันหยุดราชการราว 15–25 วัน น้อยกว่านี้ทั้งไฟล์ = คำตอบเสีย */
    private const MIN_HOLIDAYS = 8;

    /**
     * ชื่อสั้นที่คนไทยเรียกกัน — ชื่อทางการของวันสำคัญทางราชวงศ์ยาวจนตัดบรรทัดบนการ์ด
     * คีย์คือชื่อภาษาอังกฤษของ Google (ไม่ค่อยเปลี่ยน) หลังตัดคำว่า observed / Day off for
     */
    private const SHORT_NAMES = [
        "New Year's Day" => 'วันขึ้นปีใหม่',
        "New Year's Eve" => 'วันสิ้นปี',
        'New Year Special Holiday' => 'วันหยุดพิเศษปีใหม่',
        'Makha Bucha' => 'วันมาฆบูชา',
        'Chakri Day' => 'วันจักรี',
        'Songkran' => 'วันสงกรานต์',
        'Songkran Holiday' => 'วันสงกรานต์',
        'Labor Day' => 'วันแรงงาน',
        'Coronation Day' => 'วันฉัตรมงคล',
        'Royal Ploughing Ceremony Day' => 'วันพืชมงคล',
        'Visakha Bucha' => 'วันวิสาขบูชา',
        "Queen Suthida's Birthday" => 'วันเฉลิมฯ พระราชินี',
        "King Vajiralongkorn's Birthday" => 'วันเฉลิมพระชนมพรรษา ร.10',
        'Asalha Bucha' => 'วันอาสาฬหบูชา',
        'Buddhist Lent Day' => 'วันเข้าพรรษา',
        'Khao Phansa' => 'วันเข้าพรรษา',
        "The Queen Mother's Birthday" => 'วันแม่แห่งชาติ',
        'Anniversary of the Death of King Bhumibol' => 'วันนวมินทรมหาราช',
        'Chulalongkorn Day' => 'วันปิยมหาราช',
        "King Bhumibol's Birthday" => 'วันพ่อแห่งชาติ',
        'Constitution Day' => 'วันรัฐธรรมนูญ',
        'Royal Cremation Ceremony of Queen Sirikit' => 'วันพระราชพิธีถวายพระเพลิงพระบรมศพ',
    ];

    /**
     * @return array{fetched: int, created: int, updated: int, removed: int}
     */
    public function sync(): array
    {
        $english = $this->fetch((string) config('holidays.ics.en'));
        $thai = $this->fetchOptional((string) config('holidays.ics.th'));

        $rows = $this->holidays($english, $thai);
        if (count($rows) < self::MIN_HOLIDAYS) {
            throw new \RuntimeException('ปฏิทินวันหยุดมีวันหยุดน้อยผิดปกติ ('.count($rows).' วัน) — ไม่อัปเดต');
        }

        return DB::transaction(function () use ($rows) {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $holiday = PublicHoliday::firstOrNew([
                    'source' => PublicHoliday::SOURCE_GOOGLE,
                    'external_id' => $row['external_id'],
                ]);
                $isNew = ! $holiday->exists;

                // ชื่อที่แอดมินแก้เองคงไว้ — ที่เหลืออัปเดตตามปฏิทิน
                $holiday->fill([
                    'date' => $row['date'],
                    'name' => $holiday->name_locked ? $holiday->name : $row['name'],
                    'name_en' => $row['name_en'],
                    'official_name' => $row['official_name'],
                ]);
                if ($isNew) {
                    $holiday->is_active = true;
                    $holiday->name_locked = false;
                }

                if ($holiday->isDirty()) {
                    $holiday->save();
                    $isNew ? $created++ : $updated++;
                }
            }

            // ไฟล์ครอบคลุมปีไหนบ้าง — ลบเฉพาะวันที่หายไปในช่วงนั้น
            $dates = array_column($rows, 'date');
            $from = CarbonImmutable::parse(min($dates))->startOfYear()->toDateString();
            $to = CarbonImmutable::parse(max($dates))->endOfYear()->toDateString();

            $stale = PublicHoliday::where('source', PublicHoliday::SOURCE_GOOGLE)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->whereNotIn('external_id', array_column($rows, 'external_id'))
                ->get();
            $stale->each->delete();

            return [
                'fetched' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'removed' => $stale->count(),
            ];
        });
    }

    /**
     * วันหยุดนักขัตฤกษ์จากสองไฟล์ — UID ของสองภาษาไม่ตรงกัน จึงจับคู่ชื่อไทยด้วยวันที่
     * (วันเดียวกันมีหลายวันหยุด = จับตามลำดับในไฟล์)
     *
     * @param  array<string, array<string, mixed>>  $english
     * @param  array<string, array<string, mixed>>  $thai
     * @return array<int, array{external_id: string, date: string, name: string, name_en: string, official_name: ?string}>
     */
    public function holidays(array $english, array $thai): array
    {
        $thaiByDate = [];
        foreach ($thai as $event) {
            if (! str_starts_with((string) $event['description'], 'วันหยุดนักขัตฤกษ์')) {
                continue;
            }
            foreach ($event['dates'] as $date) {
                $thaiByDate[$date][] = $event['summary'];
            }
        }

        $rows = [];
        foreach ($english as $uid => $event) {
            if (! str_starts_with((string) $event['description'], 'Public holiday')) {
                continue;
            }

            foreach ($event['dates'] as $i => $date) {
                $official = isset($thaiByDate[$date]) ? array_shift($thaiByDate[$date]) : null;
                $rows[] = [
                    'external_id' => count($event['dates']) > 1 ? $uid.'#'.$i : $uid,
                    'date' => $date,
                    'name' => $this->shortName($event['summary'], $official),
                    'name_en' => $this->englishName($event['summary']),
                    'official_name' => $official,
                ];
            }
        }

        return $rows;
    }

    /**
     * อ่าน ICS เป็น [uid => {dates, summary, description}] — วันเดียวหรือหลายวัน
     *
     * @return array<string, array{dates: array<int, string>, summary: string, description: string}>
     */
    public function parse(string $ics): array
    {
        // บรรทัดยาวถูกพับด้วย CRLF + ช่องว่าง (อาจตัดกลางอักษรไทยแบบไบต์) — ต่อกลับก่อน
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $ics) ?? '';
        $lines = preg_split("/\r?\n/", $unfolded) ?: [];

        $events = [];
        $current = null;
        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];

                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($current !== null && isset($current['UID'], $current['DTSTART'])) {
                    $start = $this->date($current['DTSTART']);
                    $end = isset($current['DTEND']) ? $this->date($current['DTEND']) : null;
                    if ($start) {
                        $events[$current['UID']] = [
                            'dates' => $this->span($start, $end),
                            'summary' => $this->clean($current['SUMMARY'] ?? ''),
                            'description' => $this->clean($current['DESCRIPTION'] ?? ''),
                        ];
                    }
                }
                $current = null;

                continue;
            }
            if ($current === null || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $name = strtoupper(explode(';', $key, 2)[0]);
            $current[$name] = $value;
        }

        return $events;
    }

    /** ชื่อสั้นภาษาไทย — วันหยุดชดเชยขึ้นต้นด้วย "ชดเชย" */
    public function shortName(?string $english, ?string $official): string
    {
        $english = trim((string) $english);
        $substitute = (bool) preg_match('/^Day off for |\sobserved$/i', $english);
        $base = trim((string) preg_replace('/^Day off for |\s+observed$/i', '', $english));

        $short = self::SHORT_NAMES[$base] ?? null;
        if ($short === null) {
            $official = trim((string) $official);
            if ($official !== '') {
                $short = preg_replace('/^วันหยุดชดเชย\s*/u', '', $official) ?: $official;
                $substitute = $substitute || str_starts_with($official, 'วันหยุดชดเชย');
            } else {
                $short = $base !== '' ? $base : 'วันหยุด';
            }
        }

        if ($substitute) {
            $short = 'ชดเชย'.$short;
        }

        return mb_substr($short, 0, 120);
    }

    private function englishName(string $summary): string
    {
        $name = trim((string) preg_replace('/\s+observed$/i', ' (observed)', $summary));

        return mb_substr($name !== '' ? $name : 'Public holiday', 0, 120);
    }

    /** @return array<string, array<string, mixed>> */
    private function fetch(string $url): array
    {
        $response = Http::timeout(20)->retry(2, 500, throw: false)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('ดึงปฏิทินวันหยุดไม่สำเร็จ (HTTP '.$response->status().')');
        }

        return $this->parse($response->body());
    }

    /** ฉบับไทยมีไว้เอาชื่อทางการ — ดึงไม่ได้ก็ยังใช้ชื่อสั้นจากตารางได้ */
    private function fetchOptional(string $url): array
    {
        try {
            return $this->fetch($url);
        } catch (\Throwable $e) {
            Log::warning('HolidayCalendarService: ดึงปฏิทินภาษาไทยไม่สำเร็จ ใช้ชื่อจากตารางแทน', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function date(string $value): ?string
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($value), $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /**
     * DTEND ของวันทั้งวันคือ "วันถัดจากวันสุดท้าย"
     *
     * @return array<int, string>
     */
    private function span(string $start, ?string $end): array
    {
        $from = CarbonImmutable::parse($start);
        $until = $end ? CarbonImmutable::parse($end)->subDay() : $from;
        if ($until->lt($from) || $from->diffInDays($until) > 10) {
            $until = $from;
        }

        $dates = [];
        for ($d = $from; $d->lte($until); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /** ถอด escape ของ ICS และตัด zero-width space ที่ติดมากับชื่อบางวัน */
    private function clean(string $value): string
    {
        $value = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;

        return trim($value);
    }
}
