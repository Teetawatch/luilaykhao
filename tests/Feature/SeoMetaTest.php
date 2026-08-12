<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Place;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SPA sets its own head tags, but only after Vue boots. These assertions all
 * run against the raw response body — exactly what Facebook, LINE and Twitter
 * read, and all they will ever read.
 */
class SeoMetaTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $attributes = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'เขาช้างเผือก 2 วัน 1 คืน',
            'slug' => 'khao-chang-phueak',
            'type' => 'trekking',
            'location' => 'กาญจนบุรี',
            'description' => 'เดินป่าสันเขาช้างเผือก วิวสันหญ้าสวยที่สุดแห่งหนึ่งของไทย',
            'difficulty' => 'hard',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3500,
            'cover_image' => 'trips/khao-chang.jpg',
            'status' => 'active',
        ], $attributes));
    }

    /** Reviews hang off a booking, so a trip needs a round and a booking first. */
    private function makeReview(Trip $trip, User $user, int $rating, bool $approved): Review
    {
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-05-08',
            'return_date' => '2026-05-09',
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 3500,
            'paid_amount' => 3500,
        ]);

        return Review::create([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'trip_id' => $trip->id,
            'rating' => $rating,
            'comment' => 'ดีมาก',
            'is_approved' => $approved,
        ]);
    }

    /** Pull one meta tag's content out of raw HTML. */
    private function meta(string $html, string $attribute, string $name): ?string
    {
        $pattern = '/<meta '.$attribute.'="'.preg_quote($name, '/').'" content="([^"]*)"/';

        return preg_match($pattern, $html, $matches) ? html_entity_decode($matches[1]) : null;
    }

    /** @return array<int, array<string, mixed>> every JSON-LD block on the page */
    private function jsonLdBlocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        return array_map(fn ($json) => json_decode($json, true), $matches[1]);
    }

    private function jsonLdOfType(string $html, string $type): ?array
    {
        foreach ($this->jsonLdBlocks($html) as $block) {
            if (($block['@type'] ?? null) === $type) {
                return $block;
            }
        }

        return null;
    }

    public function test_a_trip_page_carries_the_trips_own_title_and_cover_photo(): void
    {
        $trip = $this->makeTrip();

        $html = $this->get('/trips/'.$trip->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<title>เขาช้างเผือก 2 วัน 1 คืน - ทริปเดินป่า | ลุยเลเขา Luilaykhao</title>', $html);
        $this->assertSame('เขาช้างเผือก 2 วัน 1 คืน - ทริปเดินป่า | ลุยเลเขา', $this->meta($html, 'property', 'og:title'));
        $this->assertStringContainsString('khao-chang.jpg', $this->meta($html, 'property', 'og:image'));
        $this->assertStringContainsString('khao-chang.jpg', $this->meta($html, 'name', 'twitter:image'));
        $this->assertSame('product', $this->meta($html, 'property', 'og:type'));
        $this->assertStringContainsString('/trips/khao-chang-phueak', $this->meta($html, 'property', 'og:url'));
    }

    public function test_a_trip_description_mentions_where_and_how_much(): void
    {
        $trip = $this->makeTrip();

        $html = $this->get('/trips/'.$trip->slug)->getContent();
        $description = $this->meta($html, 'name', 'description');

        $this->assertStringContainsString('กาญจนบุรี', $description);
        $this->assertStringContainsString('฿3,500', $description);
        $this->assertSame($description, $this->meta($html, 'property', 'og:description'));
    }

    public function test_a_trip_page_emits_its_price_for_the_share_card(): void
    {
        $trip = $this->makeTrip();

        $html = $this->get('/trips/'.$trip->slug)->getContent();

        $this->assertSame('3500', $this->meta($html, 'property', 'product:price:amount'));
        $this->assertSame('THB', $this->meta($html, 'property', 'product:price:currency'));
    }

    public function test_a_trip_page_emits_tourist_trip_structured_data(): void
    {
        $trip = $this->makeTrip();

        $block = $this->jsonLdOfType($this->get('/trips/'.$trip->slug)->getContent(), 'TouristTrip');

        $this->assertNotNull($block);
        $this->assertSame('เขาช้างเผือก 2 วัน 1 คืน', $block['name']);
        $this->assertEquals(3500, $block['offers']['price']);
        $this->assertSame('THB', $block['offers']['priceCurrency']);
        $this->assertSame('P2D', $block['duration']);
        $this->assertSame('กาญจนบุรี', $block['itinerary']['name']);
    }

    /**
     * Google discards a whole structured-data block that claims a rating with no
     * reviews behind it, so an unreviewed trip must not claim one.
     */
    public function test_an_unreviewed_trip_claims_no_rating(): void
    {
        $trip = $this->makeTrip();

        $block = $this->jsonLdOfType($this->get('/trips/'.$trip->slug)->getContent(), 'TouristTrip');

        $this->assertArrayNotHasKey('aggregateRating', $block);
    }

    public function test_a_reviewed_trip_reports_its_rating(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        foreach ([5, 4] as $rating) {
            $this->makeReview($trip, $user, $rating, approved: true);
        }

        // An unapproved review must not move the number a customer sees.
        $this->makeReview($trip, $user, 1, approved: false);

        $block = $this->jsonLdOfType($this->get('/trips/'.$trip->slug)->getContent(), 'TouristTrip');

        $this->assertSame('4.5', $block['aggregateRating']['ratingValue']);
        $this->assertSame(2, $block['aggregateRating']['reviewCount']);
    }

    public function test_trip_faqs_become_faq_structured_data(): void
    {
        $trip = $this->makeTrip([
            'faqs' => [
                ['question' => 'ต้องเตรียมอะไรบ้าง', 'answer' => 'เป้ 30 ลิตร รองเท้าเดินป่า และไฟฉาย'],
                ['question' => 'มีสัญญาณโทรศัพท์ไหม', 'answer' => 'มีเป็นช่วง ๆ ครับ'],
            ],
        ]);

        $block = $this->jsonLdOfType($this->get('/trips/'.$trip->slug)->getContent(), 'FAQPage');

        $this->assertNotNull($block);
        $this->assertCount(2, $block['mainEntity']);
        $this->assertSame('ต้องเตรียมอะไรบ้าง', $block['mainEntity'][0]['name']);
    }

    public function test_half_filled_faqs_do_not_emit_an_empty_block(): void
    {
        $trip = $this->makeTrip(['faqs' => [['question' => 'ยังไม่ได้ตอบ', 'answer' => '']]]);

        $html = $this->get('/trips/'.$trip->slug)->getContent();

        $this->assertNull($this->jsonLdOfType($html, 'FAQPage'));
        foreach ($this->jsonLdBlocks($html) as $block) {
            $this->assertNotEmpty($block, 'An empty JSON-LD block was rendered.');
        }
    }

    public function test_a_trip_page_has_a_breadcrumb_ending_at_the_trip(): void
    {
        $trip = $this->makeTrip();

        $crumbs = null;
        foreach ($this->jsonLdBlocks($this->get('/trips/'.$trip->slug)->getContent()) as $block) {
            if (($block['@type'] ?? null) === 'BreadcrumbList' && count($block['itemListElement']) === 3) {
                $crumbs = $block;
            }
        }

        $this->assertNotNull($crumbs);
        $this->assertSame('เขาช้างเผือก 2 วัน 1 คืน', $crumbs['itemListElement'][2]['name']);
    }

    public function test_an_unknown_trip_slug_falls_back_to_the_site_defaults(): void
    {
        $html = $this->get('/trips/no-such-trip')->assertOk()->getContent();

        $this->assertStringContainsString(config('seo.default.title'), $html);
        $this->assertSame('website', $this->meta($html, 'property', 'og:type'));
        $this->assertStringContainsString('logo.png', $this->meta($html, 'property', 'og:image'));
    }

    public function test_a_place_page_carries_the_places_own_meta(): void
    {
        $place = Place::create([
            'name' => 'ภูกระดึง',
            'slug' => 'phu-kradueng',
            'type' => 'mountain',
            'province' => 'เลย',
            'park' => 'อุทยานแห่งชาติภูกระดึง',
            'summary' => 'ภูเขายอดตัดที่มีทุ่งหญ้ากว้างบนหลังแป',
            'latitude' => 16.86,
            'longitude' => 101.79,
            'cover_image' => 'places/phu-kradueng.jpg',
            'status' => 'published',
        ]);

        $html = $this->get('/places/'.$place->slug)->assertOk()->getContent();

        $this->assertStringContainsString('ภูกระดึง', $this->meta($html, 'property', 'og:title'));
        $this->assertStringContainsString('phu-kradueng.jpg', $this->meta($html, 'property', 'og:image'));

        $block = $this->jsonLdOfType($html, 'TouristAttraction');
        $this->assertSame('ภูกระดึง', $block['name']);
        $this->assertSame(16.86, $block['geo']['latitude']);
        $this->assertSame('เลย', $block['address']['addressRegion']);
    }

    public function test_a_static_page_uses_its_configured_copy(): void
    {
        $html = $this->get('/faq')->assertOk()->getContent();

        $this->assertStringContainsString('คำถามที่พบบ่อย (FAQ) | ลุยเลเขา Luilaykhao', $html);
        $this->assertSame($this->expectedDescription('/faq'), $this->meta($html, 'name', 'description'));
    }

    public function test_the_homepage_keeps_the_site_default_copy(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame($this->expectedDescription('/'), $this->meta($html, 'name', 'description'));
        $this->assertSame(url('/'), $this->meta($html, 'property', 'og:url'));
    }

    /**
     * A customer's own booking or payment URL should never end up in an index,
     * and the crawler that finds one has no JavaScript to tell it so.
     */
    public function test_personal_urls_are_noindex_in_the_raw_html(): void
    {
        foreach (['/booking/12', '/payment/LLK-20260101-0001', '/my-bookings', '/reset-password'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertSame(
                'noindex, nofollow',
                $this->meta($html, 'name', 'robots'),
                "{$path} was left indexable.",
            );
        }
    }

    public function test_public_pages_stay_indexable(): void
    {
        foreach (['/', '/trips', '/about', '/faq'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringStartsWith('index, follow', $this->meta($html, 'name', 'robots'), $path);
        }
    }

    /**
     * Exactly one of each — a second og:title in the same document leaves the
     * unfurler to pick, and they do not all pick the same one.
     */
    public function test_share_tags_are_not_duplicated(): void
    {
        $trip = $this->makeTrip();
        $html = $this->get('/trips/'.$trip->slug)->getContent();

        foreach (['og:title', 'og:description', 'og:image', 'og:url', 'og:type'] as $property) {
            $this->assertSame(
                1,
                substr_count($html, '<meta property="'.$property.'"'),
                "{$property} appears more than once.",
            );
        }

        $this->assertSame(1, substr_count($html, '<title>'));
    }

    public function test_a_title_with_quotes_cannot_break_out_of_the_attribute(): void
    {
        $trip = $this->makeTrip(['title' => 'ทริป "สุดขอบฟ้า" <script>alert(1)</script>']);

        $html = $this->get('/trips/'.$trip->slug)->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    /**
     * คำโปรยของหน้าตาม config หลังแทน `:licence` แล้ว
     *
     * เลขที่ใบอนุญาตย้ายไปอยู่ในหน้าตั้งค่าของแอดมิน ตัว config จึงเก็บเป็น
     * placeholder ไว้ เพราะมันถูก cache (config:cache) และอ่านฐานข้อมูลไม่ได้
     */
    private function expectedDescription(string $path): string
    {
        return str_replace(
            ':licence',
            SiteSettings::licenceNo(),
            config("seo.pages.{$path}.description"),
        );
    }
}
