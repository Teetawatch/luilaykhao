<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Services\TripCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ปฏิทินทริปสาธารณะ /calendar — "เดือนนี้/เดือนหน้ามีทริปอะไร" ในลิงก์เดียว
 */
class TripCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-09-15 10:00 เวลาไทย
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'hard',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 4500,
            'status' => 'active',
        ], $overrides));
    }

    private function schedule(?Trip $trip, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => ($trip ?: $this->trip())->id,
            'departure_date' => '2026-09-20',
            'return_date' => '2026-09-21',
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function calendar(?string $month = null): array
    {
        $response = $this->getJson('/api/v1/trips/calendar'.($month ? '?month='.$month : ''));
        $response->assertOk();

        return $response->json('data');
    }

    public function test_the_month_answers_what_is_running_and_how_to_book_it(): void
    {
        $trip = $this->trip(['title' => 'ภูกระดึง', 'slug' => 'phu-kradueng']);
        $schedule = $this->schedule($trip, ['departure_date' => '2026-09-26', 'return_date' => '2026-09-27']);

        $data = $this->calendar();

        $this->assertSame('2026-09', $data['month']);
        $this->assertSame(1, $data['summary']['round_count']);
        $this->assertSame(1, $data['summary']['trip_count']);

        $round = $data['days'][0]['rounds'][0];
        $this->assertSame('ภูกระดึง', $round['trip']['title']);
        $this->assertEquals(4500, $round['price']);
        $this->assertSame('ว่าง 10 ที่', $round['status_label']);
        // กดแล้วไปหน้าทริปพร้อมเลือกรอบนั้นไว้ให้ ไม่ใช่โยนเข้าหน้าจองที่ต้องล็อกอิน
        $this->assertSame('/trips/phu-kradueng?schedule='.$schedule->id, $round['url']);
    }

    public function test_the_current_month_starts_from_today_not_the_first(): void
    {
        $trip = $this->trip();
        $this->schedule($trip, ['departure_date' => '2026-09-02', 'return_date' => '2026-09-03']);
        $this->schedule($trip, ['departure_date' => '2026-09-26', 'return_date' => '2026-09-27']);

        $data = $this->calendar();

        // รอบที่ออกไปแล้วเมื่อสองสัปดาห์ก่อนไม่ใช่คำตอบของ "เดือนนี้มีทริปอะไร"
        $this->assertCount(1, $data['days']);
        $this->assertSame('2026-09-26', $data['days'][0]['date']);
    }

    public function test_next_month_is_one_link_away(): void
    {
        $trip = $this->trip();
        $this->schedule($trip, ['departure_date' => '2026-10-10', 'return_date' => '2026-10-11']);

        $this->assertCount(0, $this->calendar()['days']);

        $next = $this->calendar('2026-10');
        $this->assertSame('2026-10', $next['month']);
        $this->assertSame('2026-10-10', $next['days'][0]['date']);
    }

    public function test_a_full_round_still_shows_up_but_says_so(): void
    {
        $trip = $this->trip();
        $this->schedule($trip, [
            'departure_date' => '2026-09-26',
            'total_seats' => 10,
            'booked_seats' => 10,
            'status' => 'full',
        ]);

        $round = $this->calendar()['days'][0]['rounds'][0];

        $this->assertTrue($round['is_full']);
        $this->assertStringContainsString('เต็มแล้ว', $round['status_label']);
        // เต็มแล้วไม่ใช่ของที่จะเอาไปขายในโซนไฟไหม้
        $this->assertSame([], $this->calendar()['hot']);
    }

    public function test_charter_and_cancelled_rounds_never_appear(): void
    {
        $trip = $this->trip();
        $this->schedule($trip, ['departure_date' => '2026-09-26', 'is_charter' => true]);
        $this->schedule($trip, ['departure_date' => '2026-09-27', 'status' => 'cancelled']);
        $this->schedule($trip, ['departure_date' => '2026-09-28', 'status' => 'closed']);

        $this->assertCount(0, $this->calendar()['days']);
    }

    public function test_a_round_of_a_hidden_trip_never_appears(): void
    {
        $trip = $this->trip(['status' => 'inactive']);
        $this->schedule($trip, ['departure_date' => '2026-09-26']);

        $this->assertCount(0, $this->calendar()['days']);
    }

    // ── ไฟไหม้ ───────────────────────────────────────────────────────────────

    public function test_hot_is_near_departure_with_seats_left(): void
    {
        $trip = $this->trip();
        $soon = $this->schedule($trip, ['departure_date' => '2026-09-18', 'booked_seats' => 8]);
        $later = $this->schedule($trip, ['departure_date' => '2026-10-30']);

        $hot = $this->calendar()['hot'];

        $this->assertCount(1, $hot);
        $this->assertSame($soon->id, $hot[0]['id']);
        $this->assertSame('อีก 3 วัน', $hot[0]['days_left_label']);
        $this->assertSame('เหลือ 2 ที่', $hot[0]['status_label']);
        $this->assertNotContains($later->id, array_column($hot, 'id'));
    }

    public function test_a_flash_sale_further_out_is_hot_too_and_shows_the_old_price(): void
    {
        $trip = $this->trip();
        $sale = $this->schedule($trip, [
            'departure_date' => '2026-10-20',
            'flash_sale_enabled' => true,
            'flash_sale_price' => 2900,
            'flash_sale_ends_at' => '2026-09-20 23:59:59',
        ]);

        $hot = $this->calendar()['hot'];

        $this->assertCount(1, $hot);
        $this->assertSame($sale->id, $hot[0]['id']);
        $this->assertTrue($hot[0]['on_flash_sale']);
        $this->assertEquals(2900, $hot[0]['price']);
        $this->assertEquals(4500, $hot[0]['original_price']);
    }

    public function test_hot_ignores_a_round_that_already_left_and_one_that_is_full(): void
    {
        $trip = $this->trip();
        $this->schedule($trip, ['departure_date' => '2026-09-14']);
        $this->schedule($trip, ['departure_date' => '2026-09-18', 'booked_seats' => 10, 'status' => 'full']);

        $this->assertSame([], $this->calendar()['hot']);
    }

    public function test_hot_is_the_same_list_whichever_month_is_open(): void
    {
        $trip = $this->trip();
        $soon = $this->schedule($trip, ['departure_date' => '2026-09-18']);

        // ไฟไหม้คือ "จากวันนี้ไป" ไม่ใช่ "ของเดือนที่กำลังเปิดดู"
        $this->assertSame($soon->id, $this->calendar('2026-11')['hot'][0]['id']);
    }

    // ── เดือนที่เลือกได้ ──────────────────────────────────────────────────────

    public function test_month_options_cover_half_a_year_ahead(): void
    {
        $months = array_column($this->calendar()['months'], 'value');

        $this->assertSame('2026-09', $months[0]);
        $this->assertCount(TripCalendarService::MONTHS_AHEAD + 1, $months);
        $this->assertSame('2027-03', end($months));
    }

    public function test_a_nonsense_month_falls_back_instead_of_failing(): void
    {
        // หน้าสาธารณะที่ถูกแปะในไลน์ ต้องไม่ตอบ error เพราะมีคนพิมพ์ URL เพี้ยน
        $this->assertSame('2026-09', $this->calendar('ไม่ใช่เดือน')['month']);
        $this->assertSame('2026-09', $this->calendar('2026-13')['month']);
        // ไกลเกินกว่าที่รอบจะถูกวางขายจริง = หนีบกลับมาที่เดือนสุดท้ายที่เปิดดูได้
        $this->assertSame('2027-03', $this->calendar('2030-01')['month']);
    }

    // ── หน้าเว็บจริง + พรีวิวตอนแปะในไลน์ ────────────────────────────────────

    public function test_the_page_says_what_is_running_before_vue_boots(): void
    {
        $trip = $this->trip(['title' => 'ภูกระดึง', 'slug' => 'phu-kradueng', 'cover_image' => 'trips/phu.jpg']);
        $this->schedule($trip, ['departure_date' => '2026-09-26', 'return_date' => '2026-09-27']);

        $response = $this->get('/calendar');

        $response->assertOk();
        // ตัวดึงพรีวิวของไลน์อ่าน HTML ครั้งเดียวแล้วหยุด ไม่รันจาวาสคริปต์
        $response->assertSee('ทริปเดือนกันยายน 2569');
        $response->assertSee('ภูกระดึง');
        $response->assertSee('/trips/phu-kradueng?schedule=', false);
    }

    public function test_the_share_preview_is_this_month_not_a_generic_sentence(): void
    {
        $trip = $this->trip(['title' => 'ภูกระดึง', 'cover_image' => 'trips/phu.jpg']);
        $this->schedule($trip, ['departure_date' => '2026-10-10']);

        $html = $this->get('/calendar/2026-10')->getContent();

        $this->assertStringContainsString('ทริปเดือนตุลาคม 2569 | รอบที่เปิดจองทั้งหมด', $html);
        $this->assertStringContainsString('1 ทริป 1 รอบ', $html);
        // รูปพรีวิวเป็นปกทริปจริง ไม่ใช่โลโก้บริษัทเหมือนกันทุกเดือน
        $this->assertStringContainsString('trips/phu.jpg', $html);
        $this->assertStringContainsString(url('/calendar/2026-10'), $html);
    }

    public function test_an_empty_month_says_so_instead_of_pretending(): void
    {
        $html = $this->get('/calendar/2026-12')->getContent();

        $this->assertStringContainsString('ยังไม่มีรอบเดินทางเปิดจองในเดือนธันวาคม 2569', $html);
    }

    public function test_the_sitemap_lists_the_months_worth_crawling(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertSee(url('/calendar/2026-09'), false);
        $response->assertSee(url('/calendar/2026-12'), false);
    }

    public function test_the_page_is_open_to_everyone(): void
    {
        $this->getJson('/api/v1/trips/calendar')->assertOk();
        $this->assertGuest();
    }
}
