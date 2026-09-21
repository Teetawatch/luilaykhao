<?php

namespace App\Support;

use App\Services\TripCalendarService;

/**
 * หน้าปฏิทินทริปก่อน Vue จะบูต
 *
 * หน้านี้เกิดมาเพื่อถูกแปะในไลน์ ซึ่งแปลว่าสิ่งแรกที่อ่านมันไม่ใช่คน แต่เป็นตัว
 * ดึงพรีวิวของไลน์ ที่ไม่รันจาวาสคริปต์สักบรรทัด ถ้าเนื้อหาอยู่แต่ในบันเดิล
 * ลิงก์ที่ทีมงานส่งไปก็จะขึ้นเป็นโลโก้บริษัทกับประโยคกลาง ๆ เหมือนกันทุกเดือน
 *
 * และคนที่กดจากไลน์ด้วยเน็ตสามจีระหว่างนั่งรถ ก็ได้อ่านรายการทริปตั้งแต่วินาที
 * แรก แทนที่จะเห็นจอขาวรอบันเดิล — Vue ล้างกล่อง #app ตอน mount อยู่แล้ว
 * เนื้อหาชุดนี้จึงไม่มีทางค้างซ้อนกับของจริง (ดู TripShell ที่ทำแบบเดียวกัน)
 */
final class CalendarShell
{
    /** จำนวนวันที่พิมพ์ลงใน shell — พอให้เห็นว่าเดือนนี้มีอะไร ไม่ใช่ทั้งปฏิทิน */
    private const DAY_LIMIT = 12;

    /**
     * payload ของปฏิทินสำหรับ path นี้ หรือ null เมื่อไม่ใช่หน้าปฏิทิน
     *
     * @return array<string, mixed>|null
     */
    public static function forPath(string $path): ?array
    {
        $path = '/'.trim($path, '/');

        if (! preg_match('#^/calendar(?:/(\d{4}-\d{2}))?$#', $path, $matches)) {
            return null;
        }

        return app(TripCalendarService::class)->forMonth($matches[1] ?? null);
    }

    /**
     * ข้อความที่ shell พิมพ์จริง — ตั้งใจให้เป็นตัวอักษร ไม่ใช่เลย์เอาต์
     *
     * @param  array<string, mixed>  $calendar
     * @return array{heading: string, summary: string, hot: array<int, array{label: string, url: string}>, days: array<int, array{label: string, rounds: array<int, array{label: string, url: string}>}>, more: ?string}
     */
    public static function present(array $calendar): array
    {
        $summary = $calendar['summary'];

        return [
            'heading' => 'ทริปเดือน'.$calendar['month_label'],
            'summary' => $summary['round_count'] > 0
                ? $summary['trip_count'].' ทริป '.$summary['round_count'].' รอบ'
                    .($summary['min_price'] ? ' เริ่มต้น '.self::baht($summary['min_price']) : '')
                : 'ยังไม่มีรอบเปิดจองในเดือนนี้ ลองดูเดือนถัดไปได้เลย',
            'hot' => collect($calendar['hot'])
                ->map(fn (array $round) => [
                    'label' => $round['trip']['title'].' · '.$round['date_label']
                        .' · '.self::baht($round['price']).' ('.$round['days_left_label'].')',
                    'url' => url($round['url']),
                ])
                ->all(),
            'days' => collect($calendar['days'])
                ->take(self::DAY_LIMIT)
                ->map(fn (array $day) => [
                    'label' => $day['date_label'],
                    'rounds' => collect($day['rounds'])
                        ->map(fn (array $round) => [
                            'label' => $round['trip']['title']
                                .($round['trip']['location'] ? ' · '.$round['trip']['location'] : '')
                                .' · '.self::baht($round['price'])
                                .' · '.$round['status_label'],
                            'url' => url($round['url']),
                        ])
                        ->all(),
                ])
                ->all(),
            'more' => count($calendar['days']) > self::DAY_LIMIT
                ? 'และอีก '.(count($calendar['days']) - self::DAY_LIMIT).' วันในเดือนนี้'
                : null,
        ];
    }

    private static function baht(float|int|null $amount): string
    {
        return '฿'.number_format((float) $amount);
    }
}
