<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A trip page's body, before any JavaScript runs.
 *
 * Every trip URL used to answer with the same markup — the site-wide link list
 * — and differed only in its <head>. Everything asserted here reads the raw
 * response body, which is what Bing, the unfurlers and the AI crawlers get, and
 * what Google reads until the page reaches its render queue days later.
 */
class TripShellContentTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $attributes = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูกระดึง 3 วัน 2 คืน',
            'slug' => 'phu-kradueng',
            'type' => 'trekking',
            'location' => 'เลย',
            'description' => "เดินขึ้นภูกระดึงทางหลังแป\nพักลานเมรุพรหม 2 คืน",
            'difficulty' => 'hard',
            'duration_days' => 3,
            'distance_km' => 26.5,
            'elevation_gain_m' => 1288,
            'max_participants' => 15,
            'price_per_person' => 4500,
            'status' => 'active',
        ], $attributes));
    }

    private function makeRound(Trip $trip, string $departure, array $attributes = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $attributes));
    }

    /** The response body only — the <head> already had this content. */
    private function body(string $path): string
    {
        $html = $this->get($path)->assertOk()->getContent();

        return substr($html, (int) strpos($html, '<body'));
    }

    public function test_a_trip_page_prints_the_trip_in_the_response_body(): void
    {
        $this->makeTrip();

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('ภูกระดึง 3 วัน 2 คืน', $body);
        $this->assertStringContainsString('เดินขึ้นภูกระดึงทางหลังแป', $body);
        $this->assertStringContainsString('พักลานเมรุพรหม 2 คืน', $body);
    }

    /**
     * The point of the whole exercise: two trips must no longer answer with the
     * same bytes. Anything that does not run JavaScript was reading one page
     * repeated once per slug.
     */
    public function test_two_trips_no_longer_share_the_same_body(): void
    {
        $this->makeTrip();
        $this->makeTrip([
            'title' => 'เขาช้างเผือก 2 วัน 1 คืน',
            'slug' => 'khao-chang-phueak',
            'description' => 'เดินสันเขาช้างเผือก',
        ]);

        $this->assertNotSame(
            $this->body('/trips/phu-kradueng'),
            $this->body('/trips/khao-chang-phueak'),
        );
    }

    public function test_the_facts_people_search_with_are_in_the_html(): void
    {
        $this->makeTrip();

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('เลย', $body);
        $this->assertStringContainsString('3 วัน', $body);
        $this->assertStringContainsString('ระดับท้าทาย', $body);
        $this->assertStringContainsString('26.5 กิโลเมตร', $body);
        $this->assertStringContainsString('1,288 เมตร', $body);
    }

    public function test_the_rounds_on_sale_are_listed_with_thai_dates(): void
    {
        $trip = $this->makeTrip();
        $departure = now('Asia/Bangkok')->addMonth();
        $this->makeRound($trip, $departure->toDateString(), ['return_date' => $departure->copy()->addDays(2)->toDateString()]);

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('รอบที่เปิดจอง', $body);
        $this->assertStringContainsString(ThaiDate::monthName((int) $departure->format('n')), $body);
        $this->assertStringContainsString('ว่าง 12 ที่', $body);
    }

    public function test_a_round_that_has_left_or_been_cancelled_is_not_advertised(): void
    {
        $trip = $this->makeTrip();
        $this->makeRound($trip, now('Asia/Bangkok')->subMonth()->toDateString());
        $this->makeRound($trip, now('Asia/Bangkok')->addMonth()->toDateString(), ['status' => 'cancelled']);

        $this->assertStringNotContainsString('รอบที่เปิดจอง', $this->body('/trips/phu-kradueng'));
    }

    public function test_a_full_round_says_so_rather_than_claiming_seats(): void
    {
        $trip = $this->makeTrip();
        $this->makeRound($trip, now('Asia/Bangkok')->addMonth()->toDateString(), ['booked_seats' => 12]);

        $this->assertStringContainsString('เต็มแล้ว', $this->body('/trips/phu-kradueng'));
    }

    /** The shell must quote the round's price, not the trip's list price. */
    public function test_the_price_comes_from_the_cheapest_round_on_sale(): void
    {
        $trip = $this->makeTrip();
        $this->makeRound($trip, now('Asia/Bangkok')->addMonth()->toDateString(), ['price_override' => 3900]);

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('฿3,900', $body);
        $this->assertStringNotContainsString('เริ่มต้น ฿4,500', $body);
    }

    public function test_the_itinerary_is_printed_day_by_day(): void
    {
        $this->makeTrip([
            'itinerary' => [[
                'sector' => 'ช่วงเดินขึ้น',
                'items' => [
                    ['day' => 1, 'title' => 'ขึ้นภูทางหลังแป', 'description' => 'เริ่มเดิน 08:00 ถึงหลังแปบ่าย'],
                    ['day' => 2, 'title' => 'ผาหล่มสัก', 'description' => 'ชมพระอาทิตย์ตกที่ผาหล่มสัก'],
                ],
            ]],
        ]);

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('ช่วงเดินขึ้น', $body);
        $this->assertStringContainsString('วันที่ 1 · ขึ้นภูทางหลังแป', $body);
        $this->assertStringContainsString('ชมพระอาทิตย์ตกที่ผาหล่มสัก', $body);
    }

    /** Trips authored before sectors existed hold a flat list of days. */
    public function test_an_itinerary_in_the_old_flat_shape_still_prints(): void
    {
        $this->makeTrip([
            'itinerary' => [
                ['day' => 1, 'title' => 'ออกเดินทาง', 'description' => 'ขึ้นรถตู้ที่กรุงเทพ'],
            ],
        ]);

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('กำหนดการเดินทาง', $body);
        $this->assertStringContainsString('วันที่ 1 · ออกเดินทาง', $body);
    }

    public function test_highlights_inclusions_preparations_and_costs_are_printed(): void
    {
        $this->makeTrip([
            'highlights' => [['title' => 'ผาหล่มสัก', 'desc' => 'จุดชมวิวที่ดังที่สุดของภูกระดึง', 'icon' => 'star']],
            'inclusions' => ['รถตู้ VIP ไป-กลับ', 'อาหาร 6 มื้อ'],
            'exclusions' => ['ค่าลูกหาบ'],
            'preparations' => ['รองเท้าเดินป่า', 'ไฟฉายคาดหัว'],
            'must_know' => [
                'items' => [['name' => 'ค่าลูกหาบ', 'price' => 30, 'price_type' => 'per_person']],
                'remarks' => 'ค่าลูกหาบคิดตามน้ำหนักจริง',
            ],
        ]);

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('จุดชมวิวที่ดังที่สุดของภูกระดึง', $body);
        $this->assertStringContainsString('รถตู้ VIP ไป-กลับ', $body);
        $this->assertStringContainsString('ค่าลูกหาบ', $body);
        $this->assertStringContainsString('รองเท้าเดินป่า', $body);
        $this->assertStringContainsString('ค่าลูกหาบคิดตามน้ำหนักจริง', $body);
    }

    public function test_faqs_are_answered_in_the_html_not_only_in_structured_data(): void
    {
        $this->makeTrip([
            'faqs' => [['question' => 'ต้องใช้ลูกหาบไหม', 'answer' => 'ไม่บังคับ แต่แนะนำสำหรับคนที่แบกเองไม่ไหว']],
        ]);

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString('ต้องใช้ลูกหาบไหม', $body);
        $this->assertStringContainsString('ไม่บังคับ แต่แนะนำ', $body);
    }

    /**
     * The trip is the page, but the links are how a crawler reaches the rest of
     * the site — losing them here would trade one gain for another.
     */
    public function test_a_trip_page_still_carries_the_site_links(): void
    {
        $this->makeTrip();

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringContainsString(url('/trips'), $body);
        $this->assertStringContainsString(url('/blog'), $body);
    }

    public function test_pages_that_are_not_trips_are_untouched(): void
    {
        $this->makeTrip();

        $body = $this->body('/about');

        $this->assertStringContainsString('แพลตฟอร์มจองและจัดทริปเที่ยว', $body);
        $this->assertStringNotContainsString('llk-trip__title', $body);
    }

    public function test_an_unknown_slug_renders_the_plain_shell(): void
    {
        // Percent-encoded: trip slugs can be Thai, and the resolver urldecodes
        // before it looks one up.
        $body = $this->body('/trips/'.rawurlencode('ไม่มีทริปนี้'));

        $this->assertStringNotContainsString('llk-trip__title', $body);
        $this->assertStringContainsString(url('/trips'), $body);
    }

    /**
     * Vue empties its mount container, so this has to be inside it — outside,
     * it would sit under the real page forever.
     */
    public function test_the_trip_markup_lives_inside_the_vue_mount_point(): void
    {
        $this->makeTrip();

        $html = $this->get('/trips/phu-kradueng')->getContent();

        $this->assertMatchesRegularExpression('#<div id="app">\s*<div class="llk-boot"#', $html);
        $this->assertStringContainsString('llk-trip__title', $html);
    }

    /** Admin-authored copy is data, not markup. */
    public function test_a_trip_cannot_inject_markup_through_its_own_copy(): void
    {
        $this->makeTrip([
            'title' => 'ทริป <script>alert(1)</script>',
            'description' => '<b>ตัวหนา</b>',
        ]);

        $body = $this->body('/trips/phu-kradueng');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<b>ตัวหนา</b>', $body);
        $this->assertStringContainsString('ตัวหนา', $body);
    }
}
