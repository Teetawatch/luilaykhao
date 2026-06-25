<?php

namespace Tests\Feature;

use App\Models\Promotion;
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
}
