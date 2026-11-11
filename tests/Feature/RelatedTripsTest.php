<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function round(Trip $trip, string $status, int $inDays = 30): void
    {
        TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays($inDays)->toDateString(),
            'return_date' => now()->addDays($inDays + 1)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => $status,
        ]);
    }

    public function test_an_open_upcoming_round_outranks_featured_and_ties_keep_id_order(): void
    {
        $trip = $this->makeTrip(['type' => 'trekking', 'region' => 'north']);
        $plain = $this->makeTrip(['type' => 'diving', 'region' => 'south']);
        $featured = $this->makeTrip(['type' => 'diving', 'region' => 'south', 'is_featured' => true]);
        $withRound = $this->makeTrip(['type' => 'diving', 'region' => 'south']);
        $closedRound = $this->makeTrip(['type' => 'diving', 'region' => 'south']);
        $plainToo = $this->makeTrip(['type' => 'diving', 'region' => 'south']);
        $this->round($withRound, 'open');
        $this->round($closedRound, 'closed');
        // รอบที่ผ่านไปแล้วไม่นับว่า "มีรอบให้จอง"
        $this->round($plain, 'open', -5);

        $slugs = collect($this->getJson("/api/v1/trips/{$trip->slug}/related")->assertOk()->json('data'))
            ->pluck('slug')
            ->all();

        $this->assertSame(
            [$withRound->slug, $featured->slug, $plain->slug, $closedRound->slug, $plainToo->slug],
            $slugs,
        );
    }

    public function test_trips_without_a_region_count_as_the_same_region(): void
    {
        $trip = $this->makeTrip(['type' => 'trekking', 'region' => null]);
        $north = $this->makeTrip(['type' => 'diving', 'region' => 'north']);
        $none = $this->makeTrip(['type' => 'diving', 'region' => null]);

        $slugs = collect($this->getJson("/api/v1/trips/{$trip->slug}/related")->assertOk()->json('data'))
            ->pluck('slug')
            ->all();

        $this->assertSame([$none->slug, $north->slug], $slugs);
    }

    public function test_only_the_six_shown_trips_are_loaded(): void
    {
        $trip = $this->makeTrip();
        for ($i = 0; $i < 12; $i++) {
            $this->round($this->makeTrip(), 'open');
        }

        DB::enableQueryLog();
        $this->getJson("/api/v1/trips/{$trip->slug}/related")->assertOk()->assertJsonCount(6, 'data');
        $scheduleLoads = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_starts_with($q['query'], 'select * from "trip_schedules" where "trip_schedules"."trip_id" in'));

        $this->assertCount(1, $scheduleLoads);
        // eager load ใส่ id ที่เป็นตัวเลขลงใน SQL ตรง ๆ (ไม่ผ่าน binding)
        preg_match('/"trip_id" in \(([^)]*)\)/', $scheduleLoads->first()['query'], $m);
        $this->assertCount(6, explode(',', $m[1] ?? ''), 'โหลดรอบเฉพาะ 6 ทริปที่แสดง');
    }
}
