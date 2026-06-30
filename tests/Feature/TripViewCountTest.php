<?php

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * จำนวนผู้ชมทริป — เปิดหน้ารายละเอียดแล้วนับวิว (กันรีเฟรชซ้ำด้วย throttle ต่อผู้ชม)
 */
class TripViewCountTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);
    }

    public function test_viewing_trip_detail_increments_view_count(): void
    {
        $trip = $this->makeTrip();

        $res = $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk();

        $this->assertSame(1, $res->json('data.views_count'));
        $this->assertSame(1, $trip->fresh()->views_count);
    }

    public function test_same_visitor_does_not_inflate_count_within_window(): void
    {
        $trip = $this->makeTrip();

        $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk();
        $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk();
        $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk();

        $this->assertSame(1, $trip->fresh()->views_count);
    }

    public function test_distinct_visitors_each_count_once(): void
    {
        $trip = $this->makeTrip();

        // Different user agents simulate distinct visitors sharing an IP.
        $this->getJson("/api/v1/trips/{$trip->slug}", ['User-Agent' => 'Visitor-A'])->assertOk();
        Cache::flush(); // clear throttle to emulate a fully different visitor
        $this->getJson("/api/v1/trips/{$trip->slug}", ['User-Agent' => 'Visitor-B'])->assertOk();

        $this->assertSame(2, $trip->fresh()->views_count);
    }

    public function test_view_count_defaults_to_zero(): void
    {
        $trip = $this->makeTrip();

        $this->assertSame(0, $trip->views_count);
    }
}
