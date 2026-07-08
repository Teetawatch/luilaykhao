<?php

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ทริปที่คุณอาจสนใจ — จัดอันดับทริป active อื่นๆ ตามความใกล้เคียง
 * (ประเภท/ภูมิภาคเดียวกันได้คะแนนสูงสุด) สูงสุด 6 รายการ
 */
class RelatedTripsTest extends TestCase
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
        ], $overrides));
    }

    public function test_returns_other_active_trips_excluding_itself(): void
    {
        $trip = $this->makeTrip();
        $this->makeTrip();
        $this->makeTrip();

        $res = $this->getJson("/api/v1/trips/{$trip->slug}/related")->assertOk();

        $slugs = collect($res->json('data'))->pluck('slug');
        $this->assertFalse($slugs->contains($trip->slug), 'ต้องไม่รวมทริปตัวเอง');
        $this->assertCount(2, $slugs);
    }

    public function test_same_type_and_region_ranks_above_unrelated(): void
    {
        $trip = $this->makeTrip(['type' => 'trekking', 'region' => 'north']);
        $twin = $this->makeTrip(['type' => 'trekking', 'region' => 'north', 'title' => 'ฝาแฝด']);
        $this->makeTrip(['type' => 'diving', 'region' => 'south', 'title' => 'ไม่เกี่ยว']);

        $res = $this->getJson("/api/v1/trips/{$trip->slug}/related")->assertOk();

        $this->assertSame($twin->slug, $res->json('data.0.slug'), 'ทริปแนวเดียวกันต้องมาก่อน');
    }

    public function test_excludes_inactive_trips(): void
    {
        $trip = $this->makeTrip();
        $this->makeTrip(['status' => 'inactive', 'title' => 'ปิดการขาย']);

        $res = $this->getJson("/api/v1/trips/{$trip->slug}/related")->assertOk();

        $this->assertCount(0, $res->json('data'));
    }

    public function test_caps_at_six_results(): void
    {
        $trip = $this->makeTrip();
        for ($i = 0; $i < 8; $i++) {
            $this->makeTrip();
        }

        $res = $this->getJson("/api/v1/trips/{$trip->slug}/related")->assertOk();

        $this->assertLessThanOrEqual(6, count($res->json('data')));
    }
}
