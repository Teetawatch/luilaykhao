<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ทริปแนะนำบนสไลด์หน้าแรกมากับ HTML shell — ถ้ารอ /trips/featured
 * รายการ "ทริปแนะนำ" ด้านขวาจะโผล่ตามมาทีหลังสไลด์แรก (กระพริบ)
 */
class HomeFeaturedShellTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ทริป '.uniqid(),
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
            'is_featured' => true,
            'cover_image' => 'https://cdn.test/cover-'.uniqid().'.jpg',
        ], $overrides));
    }

    private function round(Trip $trip, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    /** null = shell ไม่มีแท็กนี้ */
    private function shellFeatured(string $path = '/'): ?array
    {
        $html = $this->get($path)->assertOk()->getContent();
        if (! preg_match('#<script type="application/json" id="llk-home-featured">(.*?)</script>#s', $html, $m)) {
            return null;
        }

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_home_ships_the_same_featured_trips_as_the_api_slimmed_to_what_the_hero_draws(): void
    {
        $trip = $this->makeTrip(['title' => 'ภูกระดึง', 'gallery' => ['https://cdn.test/g1.jpg']]);
        $open = $this->round($trip);
        $this->round($trip, ['status' => 'closed', 'departure_date' => now()->addDays(20)->toDateString(), 'return_date' => now()->addDays(21)->toDateString()]);
        $this->round($trip, ['is_charter' => true, 'departure_date' => now()->addDays(30)->toDateString(), 'return_date' => now()->addDays(31)->toDateString()]);
        $this->makeTrip(['is_featured' => false]);

        $api = $this->getJson('/api/v1/trips/featured')->assertOk()->json('data');
        $shell = $this->shellFeatured();

        $this->assertCount(1, $shell);
        $this->assertSame(array_column($api, 'id'), array_column($shell, 'id'));
        $this->assertSame('ภูกระดึง', $shell[0]['title']);
        $this->assertSame($api[0]['min_price'], $shell[0]['min_price']);
        $this->assertSame(['https://cdn.test/g1.jpg'], $shell[0]['gallery']);
        // เฉพาะรอบเปิดจองทั่วไป — รอบปิดและรอบเหมาไม่ขึ้นชิป "รอบถัดไป"
        $this->assertSame([$open->id], array_column($shell[0]['schedules'], 'id'));
        $this->assertSame(10, $shell[0]['schedules'][0]['bookable_seats']);
        // ไม่ส่งรายละเอียดทริปทั้งก้อนมากับ HTML
        $this->assertArrayNotHasKey('description', $shell[0]);
        $this->assertArrayNotHasKey('pickup_points', $shell[0]['schedules'][0]);
    }

    public function test_caps_at_five_and_skips_trips_the_hero_cannot_draw(): void
    {
        $noImage = $this->makeTrip(['cover_image' => null]);
        foreach (range(1, 6) as $i) {
            $this->makeTrip();
        }

        $shell = $this->shellFeatured();

        $this->assertCount(5, $shell);
        $this->assertNotContains($noImage->id, array_column($shell, 'id'));
    }

    public function test_no_featured_trips_ships_an_empty_list_so_the_page_does_not_ask_again(): void
    {
        $this->makeTrip(['is_featured' => false]);

        $this->assertSame([], $this->shellFeatured());
    }

    public function test_only_the_home_page_carries_it_and_rail_thumbs_preload_on_large_screens_only(): void
    {
        $trip = $this->makeTrip(['cover_image' => 'https://cdn.test/only-cover.jpg']);

        $this->assertNotNull($this->shellFeatured('/en'));
        $this->assertNull($this->shellFeatured('/trips'));
        $this->assertNull($this->shellFeatured("/trips/{$trip->slug}"));

        $this->get('/')->assertSee('<link rel="preload" as="image" href="https://cdn.test/only-cover.jpg" media="(min-width: 1024px)" fetchpriority="low">', false);
        $this->get('/trips')->assertDontSee('media="(min-width: 1024px)"', false);
    }

    public function test_a_trip_title_cannot_close_the_script_tag(): void
    {
        $this->makeTrip(['title' => 'ทริป </script><script>alert(1)</script>']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertSame('ทริป </script><script>alert(1)</script>', $this->shellFeatured()[0]['title']);
    }
}
