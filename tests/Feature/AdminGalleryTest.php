<?php

namespace Tests\Feature;

use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แกลเลอรีภาพประทับใจ — แอดมินจัดการรูป (CRUD + จัดเรียง) และหน้าเว็บหลักดึงเฉพาะรูปที่เปิดแสดง
 */
class AdminGalleryTest extends TestCase
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

    public function test_admin_can_create_gallery_image(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/gallery', [
                'image_url' => 'https://media.luilaykhao.com/media/sunrise.jpg',
                'caption' => 'พระอาทิตย์ขึ้นที่ภูสอยดาว',
                'location' => 'ภูสอยดาว จ.พิษณุโลก',
            ])
            ->assertOk();

        $this->assertDatabaseHas('gallery_images', [
            'image_url' => 'https://media.luilaykhao.com/media/sunrise.jpg',
            'caption' => 'พระอาทิตย์ขึ้นที่ภูสอยดาว',
            'is_active' => true,
        ]);
        $this->assertSame(0, GalleryImage::first()->sort_order);
    }

    public function test_create_requires_a_valid_url(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/gallery', ['image_url' => 'not-a-url'])
            ->assertStatus(422);
    }

    public function test_public_gallery_returns_only_active_ordered(): void
    {
        GalleryImage::create(['image_url' => 'https://x/a.jpg', 'sort_order' => 2, 'is_active' => true]);
        GalleryImage::create(['image_url' => 'https://x/b.jpg', 'sort_order' => 0, 'is_active' => true]);
        GalleryImage::create(['image_url' => 'https://x/hidden.jpg', 'sort_order' => 1, 'is_active' => false]);

        $res = $this->getJson('/api/v1/gallery')->assertOk();

        $urls = collect($res->json('data'))->pluck('image_url')->all();
        $this->assertSame(['https://x/b.jpg', 'https://x/a.jpg'], $urls);
    }

    public function test_admin_can_reorder_gallery(): void
    {
        $a = GalleryImage::create(['image_url' => 'https://x/a.jpg', 'sort_order' => 0]);
        $b = GalleryImage::create(['image_url' => 'https://x/b.jpg', 'sort_order' => 1]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/gallery/reorder', ['ids' => [$b->id, $a->id]])
            ->assertOk();

        $this->assertSame(0, $b->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);
    }

    public function test_admin_can_delete_gallery_image(): void
    {
        $image = GalleryImage::create(['image_url' => 'https://x/a.jpg']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/gallery/{$image->id}")
            ->assertOk();

        $this->assertDatabaseMissing('gallery_images', ['id' => $image->id]);
    }

    public function test_guest_cannot_manage_gallery(): void
    {
        $this->postJson('/api/v1/admin/gallery', ['image_url' => 'https://x/a.jpg'])
            ->assertStatus(401);
    }
}
