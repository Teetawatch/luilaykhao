<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\TripController;
use Illuminate\Http\Request;
use Throwable;

/**
 * ทริปแนะนำบนสไลด์หน้าแรก ส่งมากับ HTML shell — ถ้ารอ /trips/featured
 * หน้าแรกจะเห็นสไลด์แบรนด์สไลด์เดียวก่อน แล้วรายการ "ทริปแนะนำ" ด้านขวาค่อยโผล่ตามมา
 *
 * ใช้คำตอบเดียวกับ API ทุกตัวอักษร: เรียก TripController::featured() ด้วย request เปล่า
 * (ไม่มี query ไม่มีผู้ใช้) จึงได้คีย์ TripListCache เดียวกับผู้เยี่ยมชมที่เรียก API —
 * ไม่ query ซ้ำ และ ?utm=… บน URL หน้าแรกไม่แตกแคชออกเป็นหลายชุด
 * แล้วตัดเหลือเฉพาะฟิลด์ที่สไลด์ใช้ (HomePage.vue heroSlides) ให้ HTML ไม่บวม
 */
final class HomeFeaturedShell
{
    /** จำนวนสไลด์ทริปบนหน้าแรก — ตรงกับ heroTrips() ใน HomePage.vue */
    public const LIMIT = 5;

    /** null = ไม่ใช่หน้าแรก หรือหาไม่ได้ — หน้าเว็บไปถาม API เอง */
    public static function forPath(string $path): ?array
    {
        if ($path !== '/') {
            return null;
        }

        try {
            $payload = app(TripController::class)
                ->featured(Request::create('/api/v1/trips/featured'))
                ->getData(true);
        } catch (Throwable $e) {
            // shell ต้องขึ้นเสมอ — ทริปแนะนำพังก็แค่กลับไปโหลดผ่าน API แบบเดิม
            report($e);

            return null;
        }

        $trips = $payload['data'] ?? null;
        if (! is_array($trips)) {
            return null;
        }

        return array_values(array_map(
            [self::class, 'slim'],
            array_slice(array_values(array_filter($trips, [self::class, 'usable'])), 0, self::LIMIT),
        ));
    }

    /**
     * รูปย่อของรายการด้านขวา — ลำดับเดียวกับ slide.thumb ใน HomePage.vue
     * (tripCardImage() แล้วค่อยรูปปก)
     *
     * @return list<string>
     */
    public static function railThumbs(array $trips): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $t) => $t['card_image'] ?: $t['thumbnail_image'] ?: $t['cover_image'] ?: null,
            $trips,
        ))));
    }

    /** เงื่อนไขเดียวกับ heroTrips(): มี slug และมีรูปให้เป็นพื้นหลัง */
    private static function usable(mixed $trip): bool
    {
        return is_array($trip)
            && ! empty($trip['slug'])
            && (! empty($trip['cover_image']) || ! empty($trip['card_image']) || ! empty($trip['thumbnail_image']));
    }

    private static function slim(array $trip): array
    {
        return [
            'id' => $trip['id'] ?? null,
            'slug' => $trip['slug'],
            'title' => $trip['title'] ?? '',
            'location' => $trip['location'] ?? '',
            'cover_image' => $trip['cover_image'] ?? null,
            'card_image' => $trip['card_image'] ?? null,
            'thumbnail_image' => $trip['thumbnail_image'] ?? null,
            'duration_days' => $trip['duration_days'] ?? null,
            'difficulty' => $trip['difficulty'] ?? null,
            'distance_km' => $trip['distance_km'] ?? null,
            'rating' => $trip['rating'] ?? null,
            'review_count' => $trip['review_count'] ?? 0,
            'min_price' => $trip['min_price'] ?? null,
            'min_original_price' => $trip['min_original_price'] ?? null,
            // แถวรูปเล็กโชว์ 4 รูปแล้วบอก +N ที่เหลือ จึงต้องส่ง URL ครบทุกรูป (แต่ส่งแค่ URL)
            'photos' => array_values(array_filter(array_map(
                fn ($p) => is_array($p) && ($url = $p['thumb_url'] ?? $p['url'] ?? null) ? ['thumb_url' => $url] : null,
                $trip['photos'] ?? [],
            ))),
            'gallery' => array_values(array_filter($trip['gallery'] ?? [], 'is_string')),
            // ชิป "รอบถัดไป" ใช้เฉพาะรอบที่เปิดจองทั่วไป — วันที่/ที่นั่งเว็บกรองต่อเอง (openRounds)
            'schedules' => array_values(array_map(
                fn ($s) => [
                    'id' => $s['id'] ?? null,
                    'status' => $s['status'],
                    'is_charter' => false,
                    'departure_date' => $s['departure_date'] ?? null,
                    'available_seats' => $s['available_seats'] ?? null,
                    'bookable_seats' => $s['bookable_seats'] ?? null,
                ],
                array_filter(
                    $trip['schedules'] ?? [],
                    fn ($s) => is_array($s) && ($s['status'] ?? null) === 'open' && empty($s['is_charter']),
                ),
            )),
        ];
    }
}
