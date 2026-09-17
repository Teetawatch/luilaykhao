<?php

namespace App\Support;

use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The links that sit in the HTML before Vue boots.
 *
 * The response body for every SPA route used to be a single empty `<div
 * id="app">` — zero words, zero links. Crawlers that never run JavaScript (Bing,
 * the LINE and Facebook unfurlers, the AI crawlers) therefore had no way to
 * learn what else this site contains, and Google only found out once our pages
 * reached its render queue, which can be days behind. sitemap.xml lists what
 * exists but says nothing about what matters; the sitelinks under a brand-name
 * search are decided mostly by internal links, and we were offering none.
 *
 * So the shell prints this list itself. Vue clears the container when it mounts
 * (`app.mount('#app')` empties the element first), so a visitor sees it only
 * while the bundle is still downloading — in place of the blank page they used
 * to get.
 *
 * Labels here are the short human names of the pages. The SEO titles in
 * config/seo.php carry keyword tails that read badly in a link list.
 */
class SiteNav
{
    /** Trips worth a link in the shell. Enough to matter, few enough to scan. */
    private const TRIP_LIMIT = 12;

    /** Cache lifetime for the trip list. The pages themselves change far slower. */
    private const TRIP_TTL_MINUTES = 60;

    /**
     * @return array<int, array{heading: string, links: array<int, array{label: string, url: string}>}>
     */
    public static function sections(): array
    {
        return [
            [
                'heading' => 'ทริป',
                'links' => self::links([
                    '/trips' => 'ค้นหาทริปทั้งหมด',
                    '/explore' => 'สำรวจทริปบนแผนที่',
                    '/find' => 'ค้นหาทริปที่ใช่',
                    '/reviews' => 'รีวิวจากนักเดินทาง',
                    '/gallery' => 'รูปจากคนที่ไปมาแล้ว',
                    '/feed' => 'ฟีดจากนักเดินทาง',
                ]),
            ],
            [
                'heading' => 'ก่อนออกเดินทาง',
                'links' => self::links([
                    '/places' => 'สถานที่ธรรมชาติในไทย',
                    '/seasons' => 'เดือนไหนไปไหนดี',
                    '/difficulty' => 'ระดับความยากเดินป่า',
                    '/checklist' => 'เช็คลิสต์ของที่ต้องเตรียม',
                    '/blog' => 'บทความ',
                    '/how-to-book' => 'วิธีการจองทริป',
                ]),
            ],
            [
                'heading' => 'เกี่ยวกับลุยเลเขา',
                'links' => self::links([
                    '/about' => 'เกี่ยวกับเรา',
                    '/goal' => 'จุดมุ่งหมายของเรา',
                    '/faq' => 'คำถามที่พบบ่อย',
                    '/contact' => 'ติดต่อเรา',
                    '/terms' => 'เงื่อนไขการให้บริการ',
                    '/privacy' => 'นโยบายความเป็นส่วนตัว',
                ]),
            ],
        ];
    }

    /**
     * Trip pages, featured first: the rounds actually on sale are the ones
     * worth pointing a crawler at.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function trips(): array
    {
        try {
            return self::cachedTrips();
        } catch (\Throwable $e) {
            // เหมือน SiteSettings::all() — ลิงก์ชุดนี้ถูกอ่านตอนเรนเดอร์ทุกหน้า
            // ของเว็บ ถ้าฐานข้อมูลสะดุดแล้วปล่อยให้ exception หลุดออกไป ทั้งเว็บ
            // จะ 500 เพราะเรื่องที่แค่ลิงก์หายไปชั่วคราวก็พอ (ยังส่งเข้า Sentry)
            report($e);

            return [];
        }
    }

    /** @return array<int, array{label: string, url: string}> */
    private static function cachedTrips(): array
    {
        return Cache::remember(
            'site-nav:trips',
            now()->addMinutes(self::TRIP_TTL_MINUTES),
            function (): array {
                $onSale = self::publishedTrips()
                    ->whereHas('schedules', fn ($q) => $q
                        ->where('status', 'open')
                        // Thai wall-clock: a round departing today is still on
                        // sale here even when UTC has already rolled over.
                        ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
                    )
                    ->get();

                // A quiet week with nothing open would otherwise strip every
                // trip link out of the shell, which is when they matter most.
                $trips = $onSale->isNotEmpty()
                    ? $onSale
                    : self::publishedTrips()->get();

                return $trips
                    ->map(fn (Trip $trip) => [
                        'label' => $trip->title,
                        'url' => url('/trips/'.$trip->slug),
                    ])
                    // ->all(): a cached Collection comes back as an object and
                    // stops behaving like the array callers iterate.
                    ->all();
            },
        );
    }

    /** @return Builder<Trip> */
    private static function publishedTrips()
    {
        return Trip::query()
            ->whereIn('status', ['active', 'published'])
            ->orderByDesc('is_featured')
            ->orderBy('title')
            ->limit(self::TRIP_LIMIT);
    }

    /**
     * @param  array<string, string>  $paths  path => label
     * @return array<int, array{label: string, url: string}>
     */
    private static function links(array $paths): array
    {
        $links = [];

        foreach ($paths as $path => $label) {
            $links[] = ['label' => $label, 'url' => url($path)];
        }

        return $links;
    }
}
