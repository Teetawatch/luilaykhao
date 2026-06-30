<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หมวดหมู่กิจกรรม — แอดมินจัดการการ์ดหน้าแรก (ภาพ/สี/คำโปรย/ลำดับ) และหน้าเว็บดึงเฉพาะที่เปิดแสดง
 */
class CategoryShowcaseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_admin_can_create_category_with_showcase_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/categories', [
                'name' => 'ดำน้ำตื้น (Snorkeling)',
                'display_title' => 'Snorkeling',
                'subtitle' => 'สำรวจโลกใต้ทะเล',
                'cta_text' => 'ดูทริปดำน้ำ',
                'icon' => 'scuba_diving',
                'image_url' => 'https://media.luilaykhao.com/media/snorkel.jpg',
                'color' => '#3B9DD4',
                'bg_color' => '#E8F4FA',
                'is_popular' => true,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('categories', [
            'slug' => 'snorkeling',
            'display_title' => 'Snorkeling',
            'cta_text' => 'ดูทริปดำน้ำ',
            'color' => '#3B9DD4',
            'is_popular' => true,
        ]);
    }

    public function test_order_auto_increments_when_omitted(): void
    {
        Category::create(['name' => 'A', 'slug' => 'a', 'order' => 5]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/categories', ['name' => 'B'])
            ->assertStatus(201);

        $this->assertSame(6, Category::where('slug', 'b')->first()->order);
    }

    public function test_admin_can_update_showcase_fields(): void
    {
        $cat = Category::create(['name' => 'เดินป่า', 'slug' => 'trekking', 'order' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/categories/{$cat->id}", [
                'subtitle' => 'ผจญภัยสู่ยอดเขา',
                'image_url' => 'https://media.luilaykhao.com/media/trek.jpg',
                'is_popular' => true,
            ])
            ->assertOk();

        $cat->refresh();
        $this->assertSame('ผจญภัยสู่ยอดเขา', $cat->subtitle);
        $this->assertTrue($cat->is_popular);
    }

    public function test_admin_can_reorder_categories(): void
    {
        $a = Category::create(['name' => 'A', 'slug' => 'a', 'order' => 0]);
        $b = Category::create(['name' => 'B', 'slug' => 'b', 'order' => 1]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/categories/reorder', ['ids' => [$b->id, $a->id]])
            ->assertOk();

        $this->assertSame(0, $b->fresh()->order);
        $this->assertSame(1, $a->fresh()->order);
    }

    public function test_public_index_returns_active_ordered_with_showcase_fields(): void
    {
        Category::create(['name' => 'Second', 'slug' => 'second', 'order' => 2, 'is_active' => true, 'image_url' => 'https://x/b.jpg']);
        Category::create(['name' => 'First', 'slug' => 'first', 'order' => 1, 'is_active' => true, 'color' => '#111111']);
        Category::create(['name' => 'Hidden', 'slug' => 'hidden', 'order' => 0, 'is_active' => false]);

        $res = $this->getJson('/api/v1/categories')->assertOk();

        $slugs = collect($res->json('data'))->pluck('slug')->all();
        $this->assertSame(['first', 'second'], $slugs);
        $this->assertSame('#111111', $res->json('data.0.color'));
    }

    public function test_guest_cannot_manage_categories(): void
    {
        $this->postJson('/api/v1/admin/categories', ['name' => 'X'])
            ->assertStatus(401);
    }
}
