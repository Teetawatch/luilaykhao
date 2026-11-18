<?php

namespace Tests\Feature;

use App\Models\HeroSlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รูปสไลด์หน้าแรกมากับ HTML shell — ถ้าหน้าแรกต้องรอ API ทุกครั้งที่รีเฟรช
 * จะเห็นรูปสำรองชุดเก่าวาบหนึ่งก่อนแล้วค่อยสลับเป็นรูปจริง
 */
class HeroSlideShellTest extends TestCase
{
    use RefreshDatabase;

    private function shellUrls(string $path = '/'): array
    {
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<meta name="llk:hero-images" content="([^"]*)">#', $html);
        preg_match('#<meta name="llk:hero-images" content="([^"]*)">#', $html, $m);

        return json_decode(html_entity_decode($m[1]), true);
    }

    private function slide(string $url, int $order, bool $active = true): HeroSlide
    {
        return HeroSlide::create(['image_url' => $url, 'sort_order' => $order, 'is_active' => $active]);
    }

    public function test_the_shell_carries_active_slides_in_order_and_preloads_the_first_on_home(): void
    {
        $this->slide('https://cdn.test/b.jpg', 2);
        $this->slide('https://cdn.test/a.jpg', 1);
        $this->slide('https://cdn.test/hidden.jpg', 0, active: false);

        $this->assertSame(['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'], $this->shellUrls());
        $this->get('/')->assertSee('<link rel="preload" as="image" href="https://cdn.test/a.jpg"', false);
        $this->get('/trips')->assertDontSee('rel="preload" as="image"', false);
    }

    public function test_no_slides_ships_an_empty_list_so_the_page_uses_its_own_fallback(): void
    {
        $this->assertSame([], $this->shellUrls());
    }

    public function test_admin_changes_reach_the_next_page_load(): void
    {
        $a = $this->slide('https://cdn.test/a.jpg', 0);
        $b = $this->slide('https://cdn.test/b.jpg', 1);
        $this->assertSame(['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'], $this->shellUrls());

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        // reorder ใช้ query builder update() ซึ่งไม่ยิง event ของโมเดล
        $this->postJson('/api/v1/admin/hero-slides/reorder', ['ids' => [$b->id, $a->id]])->assertOk();
        $this->assertSame(['https://cdn.test/b.jpg', 'https://cdn.test/a.jpg'], $this->shellUrls());

        $this->putJson("/api/v1/admin/hero-slides/{$b->id}", ['is_active' => false])->assertOk();
        $this->assertSame(['https://cdn.test/a.jpg'], $this->shellUrls());

        $this->deleteJson("/api/v1/admin/hero-slides/{$a->id}")->assertOk();
        $this->assertSame([], $this->shellUrls());
    }
}
