<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionFlashSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_active_exposes_flash_sale_fields(): void
    {
        Promotion::create([
            'code' => 'FLASH50',
            'name' => 'Flash 50%',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
            'is_flash_sale' => true,
            'max_uses' => 100,
            'used_count' => 40,
            'ends_at' => now()->addHours(3),
        ]);

        $response = $this->getJson('/api/v1/promotions/active');

        $response->assertOk();
        $promo = $response->json('data.0');
        $this->assertSame('FLASH50', $promo['code']);
        $this->assertTrue($promo['is_flash_sale']);
        $this->assertNotNull($promo['ends_at']);
        $this->assertSame(100, $promo['max_uses']);
        $this->assertSame(40, $promo['used_count']);
    }

    public function test_lapsed_flash_sale_is_excluded(): void
    {
        Promotion::create([
            'code' => 'EXPIRED',
            'name' => 'Gone',
            'type' => 'percent',
            'value' => 30,
            'is_active' => true,
            'is_flash_sale' => true,
            'ends_at' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/v1/promotions/active');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertNotContains('EXPIRED', $codes);
    }

    public function test_flash_sales_sort_before_regular_promotions(): void
    {
        Promotion::create([
            'code' => 'REGULAR',
            'name' => 'Regular',
            'type' => 'fixed',
            'value' => 100,
            'is_active' => true,
            'is_flash_sale' => false,
        ]);
        Promotion::create([
            'code' => 'FLASH',
            'name' => 'Flash',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
            'is_flash_sale' => true,
            'ends_at' => now()->addDay(),
        ]);

        $codes = collect($this->getJson('/api/v1/promotions/active')->json('data'))
            ->pluck('code')
            ->all();

        $this->assertSame('FLASH', $codes[0]);
    }

    public function test_public_active_lists_the_trip_categories_a_coupon_covers(): void
    {
        $trip = fn (string $slug, string $type) => Trip::create([
            'title' => $slug, 'slug' => $slug, 'type' => $type, 'location' => 'x',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 10,
            'price_per_person' => 1000, 'status' => 'active',
        ]);
        $sea1 = $trip('sea-1', 'sea');
        $sea2 = $trip('sea-2', 'sea');
        $hike = $trip('hike-1', 'trekking');

        Promotion::create([
            'code' => 'SEA', 'name' => 'Sea', 'type' => 'fixed', 'value' => 100,
            'is_active' => true, 'trip_ids' => [$sea1->id, $sea2->id],
        ]);
        Promotion::create([
            'code' => 'MIXED', 'name' => 'Mixed', 'type' => 'fixed', 'value' => 100,
            'is_active' => true, 'trip_ids' => [(string) $hike->id, $sea1->id, 999999],
        ]);
        Promotion::create([
            'code' => 'ALL', 'name' => 'All', 'type' => 'fixed', 'value' => 100,
            'is_active' => true, 'trip_ids' => [],
        ]);

        $byCode = collect($this->getJson('/api/v1/promotions/active')->json('data'))->keyBy('code');

        $this->assertSame(['sea'], $byCode['SEA']['trip_types']);
        $this->assertSame(['trekking', 'sea'], $byCode['MIXED']['trip_types']);
        $this->assertNull($byCode['ALL']['trip_types']);
        $this->assertNull($byCode['ALL']['trip_ids']);
    }
}
