<?php

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * แถบเลือกปลายทางบนหน้ารวมทริป — ในประเทศแยกตามภาค ต่างประเทศแยกตามประเทศ
 *
 * จำนวนต้องมาจากข้อมูลจริง และต้องไม่โผล่ตัวเลือกที่กดแล้วเจอหน้าว่าง
 */
class TripDestinationFacetsTest extends TestCase
{
    use RefreshDatabase;

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ทริปทดสอบ',
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'ที่ไหนสักแห่ง',
            'destination_type' => 'domestic',
            'region' => 'north',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 3500,
            'status' => 'active',
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('trips-destinations');
    }

    public function test_it_counts_domestic_trips_by_region(): void
    {
        $this->trip(['region' => 'north']);
        $this->trip(['region' => 'north']);
        $this->trip(['region' => 'south']);

        $data = $this->getJson('/api/v1/trips/destinations')->assertOk()->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame(3, $data['domestic']['count']);

        $regions = collect($data['domestic']['regions']);
        $this->assertSame(2, $regions->firstWhere('key', 'north')['count']);
        $this->assertSame('ภาคเหนือ', $regions->firstWhere('key', 'north')['label']);
        $this->assertSame(1, $regions->firstWhere('key', 'south')['count']);
    }

    public function test_it_counts_international_trips_by_country_most_first(): void
    {
        $this->trip(['destination_type' => 'international', 'country_code' => 'NP', 'region' => null]);
        $this->trip(['destination_type' => 'international', 'country_code' => 'NP', 'region' => null]);
        $this->trip(['destination_type' => 'international', 'country_code' => 'JP', 'region' => null]);

        $data = $this->getJson('/api/v1/trips/destinations')->assertOk()->json('data');

        $this->assertSame(3, $data['international']['count']);
        $this->assertSame(0, $data['domestic']['count']);

        $countries = $data['international']['countries'];
        $this->assertSame('NP', $countries[0]['code']);
        $this->assertSame('เนปาล', $countries[0]['name']);
        $this->assertSame('🇳🇵', $countries[0]['flag']);
        $this->assertSame(2, $countries[0]['count']);
        $this->assertSame('JP', $countries[1]['code']);
    }

    public function test_regions_and_countries_without_trips_are_left_out(): void
    {
        $this->trip(['region' => 'north']);
        // ปิดขายอยู่ ไม่ควรนับและไม่ควรทำให้ภาคใต้โผล่ขึ้นมา
        $this->trip(['region' => 'south', 'status' => 'inactive']);

        $data = $this->getJson('/api/v1/trips/destinations')->assertOk()->json('data');

        $this->assertSame(1, $data['total']);
        $this->assertSame(['north'], collect($data['domestic']['regions'])->pluck('key')->all());
        $this->assertSame([], $data['international']['countries']);
    }

    public function test_a_country_code_outside_the_registry_is_dropped(): void
    {
        $this->trip(['destination_type' => 'international', 'country_code' => 'ZZ', 'region' => null]);

        $data = $this->getJson('/api/v1/trips/destinations')->assertOk()->json('data');

        // ยังนับรวมในยอดต่างประเทศ แต่แสดงเป็นชิปไม่ได้เพราะไม่มีชื่อไทย
        $this->assertSame(1, $data['international']['count']);
        $this->assertSame([], $data['international']['countries']);
    }

    /**
     * cache driver จริงบนโปรดักชัน serialize ค่าที่เก็บไว้ ถ้าเก็บเป็น Collection
     * รอบที่อ่านจากแคชจะคืน JSON เป็นออบเจ็กต์แทนลิสต์ แล้วหน้าเว็บพัง
     */
    public function test_cached_payload_stays_a_plain_list(): void
    {
        $this->trip(['region' => 'north']);
        $this->trip(['destination_type' => 'international', 'country_code' => 'NP', 'region' => null]);

        $this->getJson('/api/v1/trips/destinations')->assertOk();

        $cached = unserialize(serialize(Cache::get('trips-destinations')));

        $this->assertIsList($cached['domestic']['regions']);
        $this->assertIsList($cached['international']['countries']);
        $this->assertSame('NP', $cached['international']['countries'][0]['code']);
    }

    public function test_trips_can_be_filtered_by_region(): void
    {
        $this->trip(['title' => 'ดอยหลวง', 'region' => 'north']);
        $this->trip(['title' => 'เขาหลัก', 'region' => 'south']);

        $data = $this->getJson('/api/v1/trips?region=north')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('ดอยหลวง', $data[0]['title']);
    }
}
