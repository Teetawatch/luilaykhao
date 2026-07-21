<?php

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การเรียงรายการทริปต้องทำที่ backend เพื่อให้ครอบคลุมทุกหน้า
 * — เดิมหน้าเว็บเรียงเองหลังโหลด ทำให้ "ราคาน้อยไปมาก" เรียงแค่ทริปในหน้าปัจจุบัน
 */
class TripListSortTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title, int $price): Trip
    {
        return Trip::create([
            'title' => $title,
            'slug' => str()->slug($title),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => $price,
            'status' => 'active',
        ]);
    }

    public function test_price_asc_orders_cheapest_first(): void
    {
        $this->makeTrip('Mid', 2000);
        $this->makeTrip('Cheap', 900);
        $this->makeTrip('Pricey', 5000);

        $titles = collect($this->getJson('/api/v1/trips?sort=price_asc')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertSame(['Cheap', 'Mid', 'Pricey'], $titles->all());
    }

    public function test_price_desc_orders_most_expensive_first(): void
    {
        $this->makeTrip('Mid', 2000);
        $this->makeTrip('Cheap', 900);
        $this->makeTrip('Pricey', 5000);

        $titles = collect($this->getJson('/api/v1/trips?sort=price_desc')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertSame(['Pricey', 'Mid', 'Cheap'], $titles->all());
    }

    public function test_price_sort_spans_every_page_not_just_the_first(): void
    {
        $this->makeTrip('Pricey', 5000);
        $this->makeTrip('Mid', 2000);
        // ถูกที่สุดถูกสร้างท้ายสุด — ถ้าเรียงฝั่งหน้าเว็บ ทริปนี้จะตกไปอยู่หน้า 2 และไม่มีวันขึ้นมาหน้าแรก
        $this->makeTrip('Cheap', 900);

        $first = $this->getJson('/api/v1/trips?sort=price_asc&per_page=1')->assertOk()->json('data');

        $this->assertSame('Cheap', $first[0]['title']);
    }

    public function test_unknown_sort_falls_back_to_the_popular_ordering(): void
    {
        $this->makeTrip('Mid', 2000);
        $this->makeTrip('Cheap', 900);

        $default = collect($this->getJson('/api/v1/trips')->assertOk()->json('data'))->pluck('title');
        $bogus = collect($this->getJson('/api/v1/trips?sort=nonsense')->assertOk()->json('data'))->pluck('title');

        // ค่าที่ไม่รู้จักต้องไม่ทำให้พัง และต้องได้ลำดับเดียวกับค่าเริ่มต้น
        $this->assertSame($default->all(), $bogus->all());
        $this->assertCount(2, $bogus);
    }
}
