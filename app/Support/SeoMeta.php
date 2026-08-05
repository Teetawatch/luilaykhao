<?php

namespace App\Support;

use App\Models\Place;
use App\Models\Trip;
use Illuminate\Support\Str;

/**
 * Works out the title, description, share image and structured data for a URL
 * the SPA owns, so those tags can be in the HTML before any JavaScript runs.
 *
 * Why this exists: the Vue app already sets all of this through @unhead, but it
 * sets it *after* boot. Google renders JS and sees it; Facebook, LINE, Twitter,
 * Messenger and every other unfurler do not — they read the response body once
 * and stop. Until now every trip shared into a LINE group unfurled as the same
 * company logo and the same generic sentence, whatever the trip was.
 *
 * Kept deliberately parallel to the SPA's own head config: TripDetailPage.vue
 * builds the same title shape, the same description and the same TouristTrip
 * JSON-LD. If you change one, change the other.
 */
class SeoMeta
{
    /** How trip types read in a title. */
    private const TRIP_TYPE_LABELS = [
        'trekking' => 'เดินป่า',
        'snorkeling' => 'ดำน้ำตื้น',
        'van' => 'เช่ารถตู้นำเที่ยว',
    ];

    /**
     * @return array{
     *     title: string, og_title: string, description: string, robots: string,
     *     canonical: string, image: string, image_alt: string, type: string,
     *     extra: array<string, string>, json_ld: array<int, array<string, mixed>>
     * }
     */
    public static function for(string $path): array
    {
        $path = '/'.trim($path, '/');

        if (preg_match('#^/trips/([^/]+)$#', $path, $matches)) {
            $resolved = self::trip(urldecode($matches[1]), $path);
            if ($resolved) {
                return $resolved;
            }
        }

        if (preg_match('#^/places/([^/]+)$#', $path, $matches)) {
            $resolved = self::place(urldecode($matches[1]), $path);
            if ($resolved) {
                return $resolved;
            }
        }

        return self::staticPage($path);
    }

    /**
     * A trip: the page that actually gets shared. Carries its own cover photo,
     * its real price, and the rating that earns a stars row in Google results.
     */
    private static function trip(string $slug, string $path): ?array
    {
        $trip = Trip::where('slug', $slug)->first();

        if (! $trip) {
            return null;
        }

        $typeLabel = self::TRIP_TYPE_LABELS[$trip->type] ?? 'ท่องเที่ยว';
        $title = $trip->title.' - ทริป'.$typeLabel;
        $image = MediaDisk::url($trip->cover_image) ?: self::fallbackImage();
        $canonical = url($path);

        // One aggregate rather than a separate avg() and count(): this runs on
        // every load of the most-visited page on the site.
        $reviews = $trip->reviews()
            ->where('is_approved', true)
            ->selectRaw('avg(rating) as average, count(*) as total')
            ->first();

        $jsonLd = [self::tripJsonLd(
            $trip,
            $canonical,
            $image,
            $typeLabel,
            (float) ($reviews->average ?? 0),
            (int) ($reviews->total ?? 0),
        )];

        // faqJsonLd yields [] when every entry is half-filled, which would emit a
        // bare "[]" script tag — worse than no FAQ markup at all.
        $faqJsonLd = self::faqJsonLd($trip->faqs ?? []);
        if ($faqJsonLd !== []) {
            $jsonLd[] = $faqJsonLd;
        }

        $jsonLd[] = self::breadcrumbJsonLd([
            'หน้าแรก' => url('/'),
            'ค้นหาทริปทั้งหมด' => url('/trips'),
            $trip->title => $canonical,
        ]);

        return self::assemble([
            'title' => $title,
            'description' => self::tripDescription($trip),
            'canonical' => $canonical,
            'image' => $image,
            'image_alt' => $trip->title.' - ลุยเลเขา',
            'type' => 'product',
            'extra' => [
                'product:price:amount' => (string) (float) $trip->price_per_person,
                'product:price:currency' => 'THB',
            ],
            'json_ld' => $jsonLd,
        ]);
    }

    private static function place(string $slug, string $path): ?array
    {
        $place = Place::where('slug', $slug)->first();

        if (! $place) {
            return null;
        }

        $canonical = url($path);
        $image = $place->coverUrl() ?: self::fallbackImage();

        $where = collect([$place->park, $place->province])->filter()->implode(' ');
        $description = Str::limit(strip_tags((string) ($place->summary ?: $place->description)), 155)
            ?: trim($place->name.' '.$where.' ข้อมูลความสูง ระยะเดิน ระดับความยาก และช่วงเวลาที่ควรไป');

        return self::assemble([
            'title' => $place->name.($where ? ' '.$where : ''),
            'description' => $description,
            'canonical' => $canonical,
            'image' => $image,
            'image_alt' => $place->name.' - ลุยเลเขา',
            'type' => 'article',
            'json_ld' => [
                self::placeJsonLd($place, $canonical, $image),
                self::breadcrumbJsonLd([
                    'หน้าแรก' => url('/'),
                    'สถานที่ธรรมชาติในไทย' => url('/places'),
                    $place->name => $canonical,
                ]),
            ],
        ]);
    }

    private static function staticPage(string $path): array
    {
        $default = config('seo.default');
        $page = config('seo.pages.'.$path, []);

        return self::assemble([
            'title' => $page['title'] ?? $default['title'],
            'description' => $page['description'] ?? $default['description'],
            'canonical' => url($path),
            'image' => self::fallbackImage(),
            'image_alt' => config('seo.fallback_image_alt'),
            'type' => $page['type'] ?? $default['type'],
            'robots' => $page['robots'] ?? (self::isPrivatePath($path) ? 'noindex, nofollow' : $default['robots']),
        ]);
    }

    /**
     * Personal URLs (someone's booking, someone's payment page) that were never
     * meant to be indexed even though they are reachable without a session in
     * the sense a crawler cares about — it will get the SPA shell either way.
     */
    private static function isPrivatePath(string $path): bool
    {
        foreach (config('seo.noindex_prefixes', []) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function assemble(array $parts): array
    {
        $default = config('seo.default');
        $title = $parts['title'] ?? $default['title'];

        return [
            'title' => $title.config('seo.title_suffix'),
            'og_title' => $title.config('seo.og_title_suffix'),
            'description' => $parts['description'] ?: $default['description'],
            'robots' => $parts['robots'] ?? $default['robots'],
            'canonical' => $parts['canonical'],
            'image' => $parts['image'],
            'image_alt' => $parts['image_alt'],
            'type' => $parts['type'] ?? $default['type'],
            'extra' => $parts['extra'] ?? [],
            'json_ld' => $parts['json_ld'] ?? [],
        ];
    }

    private static function tripDescription(Trip $trip): string
    {
        $location = $trip->location ? ' สถานที่: '.$trip->location : '';
        $price = $trip->price_per_person
            ? ' ราคาเริ่มต้น ฿'.number_format((float) $trip->price_per_person)
            : '';

        $body = Str::limit(strip_tags((string) $trip->description), 120);

        return trim($trip->title.' - ลุยเลเขา'.$location.$price.' '.$body);
    }

    private static function tripJsonLd(
        Trip $trip,
        string $canonical,
        string $image,
        string $typeLabel,
        float $rating,
        int $reviewCount,
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'TouristTrip',
            'name' => $trip->title,
            'description' => (string) $trip->description,
            'url' => $canonical,
            'image' => $image,
            'touristType' => $typeLabel,
            'provider' => [
                '@type' => 'TravelAgency',
                'name' => 'ลุยเลเขา Luilaykhao',
                'url' => url('/'),
                'telephone' => '+66-62-612-6006',
            ],
            'offers' => [
                '@type' => 'Offer',
                'price' => (float) $trip->price_per_person,
                'priceCurrency' => 'THB',
                'availability' => 'https://schema.org/InStock',
                'url' => $canonical,
            ],
        ];

        if ($trip->location) {
            $data['itinerary'] = ['@type' => 'Place', 'name' => $trip->location];
        }

        if ($trip->duration_days) {
            $data['duration'] = 'P'.$trip->duration_days.'D';
        }

        // Google drops the whole block if aggregateRating is present but empty,
        // so only claim a rating once a review actually exists.
        if ($reviewCount > 0 && $rating > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format($rating, 1),
                'reviewCount' => $reviewCount,
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }

        return $data;
    }

    private static function placeJsonLd(Place $place, string $canonical, string $image): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'TouristAttraction',
            'name' => $place->name,
            'description' => strip_tags((string) ($place->summary ?: $place->description)),
            'url' => $canonical,
            'image' => $image,
        ];

        if ($place->latitude && $place->longitude) {
            $data['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $place->latitude,
                'longitude' => $place->longitude,
            ];
        }

        if ($place->province) {
            $data['address'] = [
                '@type' => 'PostalAddress',
                'addressRegion' => $place->province,
                'addressCountry' => 'TH',
            ];
        }

        return $data;
    }

    /** @param  array<string, string>  $faqs */
    private static function faqJsonLd(array $faqs): array
    {
        $questions = [];

        foreach ($faqs as $faq) {
            if (empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
            ];
        }

        return $questions === [] ? [] : [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $questions,
        ];
    }

    /** @param  array<string, string>  $crumbs  name => url, in order */
    private static function breadcrumbJsonLd(array $crumbs): array
    {
        $items = [];
        $position = 1;

        foreach ($crumbs as $name => $url) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
                'item' => $url,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    private static function fallbackImage(): string
    {
        return asset(config('seo.fallback_image'));
    }
}
