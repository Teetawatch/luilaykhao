<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Trip;
use App\Models\User;
use App\Services\ArticleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArticleTest extends TestCase
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

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Phu Kradueng',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Loei',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_create_article_with_tags_and_related_trips(): void
    {
        $category = ArticleCategory::create(['name' => 'เดินป่า', 'slug' => 'trekking']);
        $trip = $this->makeTrip();

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/articles', [
            'title' => 'เตรียมตัวเดินป่าภูกระดึง',
            'excerpt' => 'คู่มือเตรียมตัวฉบับสมบูรณ์',
            'body' => '<p>เนื้อหา</p><script>alert(1)</script>',
            'category_id' => $category->id,
            'status' => 'published',
            'tags' => ['ภูกระดึง', 'เดินป่า'],
            'trip_ids' => [$trip->id],
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.title', 'เตรียมตัวเดินป่าภูกระดึง')
            ->assertJsonPath('data.category.slug', 'trekking');

        $article = Article::first();
        // HTML sanitized: the script tag must be stripped.
        $this->assertStringNotContainsString('<script>', $article->body);
        $this->assertStringContainsString('เนื้อหา', $article->body);
        // Slug preserves Thai characters.
        $this->assertSame('เตรียมตัวเดินป่าภูกระดึง', $article->slug);
        $this->assertCount(2, $article->tags);
        $this->assertCount(1, $article->trips);
        $this->assertNotNull($article->published_at);
        $this->assertGreaterThanOrEqual(1, $article->reading_minutes);
    }

    public function test_slug_collisions_get_a_numeric_suffix(): void
    {
        $service = app(ArticleService::class);
        $a = $service->create(['title' => 'ทริคเที่ยว', 'body' => '<p>a</p>']);
        $b = $service->create(['title' => 'ทริคเที่ยว', 'body' => '<p>b</p>']);

        $this->assertSame('ทริคเที่ยว', $a->slug);
        $this->assertSame('ทริคเที่ยว-2', $b->slug);
    }

    public function test_public_index_returns_only_published_articles(): void
    {
        $service = app(ArticleService::class);
        $service->create(['title' => 'Published', 'body' => '<p>x</p>', 'status' => 'published']);
        $service->create(['title' => 'Draft', 'body' => '<p>y</p>', 'status' => 'draft']);

        $res = $this->getJson('/api/v1/articles');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title');
        $this->assertContains('Published', $titles);
        $this->assertNotContains('Draft', $titles);
        // List payload omits the body to stay light.
        $this->assertArrayNotHasKey('body', $res->json('data.0'));
    }

    public function test_public_show_hides_drafts_and_increments_views(): void
    {
        $service = app(ArticleService::class);
        $draft = $service->create(['title' => 'Secret', 'body' => '<p>z</p>', 'status' => 'draft']);
        $live = $service->create(['title' => 'Live', 'body' => '<p>hello</p>', 'status' => 'published']);

        $this->getJson("/api/v1/articles/{$draft->slug}")->assertNotFound();

        $res = $this->getJson("/api/v1/articles/{$live->slug}");
        $res->assertOk()->assertJsonPath('data.title', 'Live');
        $this->assertSame(1, $live->fresh()->views);
    }

    public function test_public_index_filters_by_category(): void
    {
        $cat = ArticleCategory::create(['name' => 'ทะเล', 'slug' => 'sea']);
        $service = app(ArticleService::class);
        $service->create(['title' => 'Sea trip', 'body' => '<p>a</p>', 'status' => 'published', 'category_id' => $cat->id]);
        $service->create(['title' => 'Mountain', 'body' => '<p>b</p>', 'status' => 'published']);

        $res = $this->getJson('/api/v1/articles?category=sea');

        $titles = collect($res->json('data'))->pluck('title');
        $this->assertEquals(['Sea trip'], $titles->all());
    }

    public function test_publish_toggle_stamps_published_at_once(): void
    {
        $service = app(ArticleService::class);
        $article = $service->create(['title' => 'Toggle', 'body' => '<p>a</p>', 'status' => 'draft']);
        $this->assertNull($article->published_at);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/articles/{$article->id}/publish", ['published' => true])
            ->assertOk();
        $firstPublishedAt = $article->fresh()->published_at;
        $this->assertNotNull($firstPublishedAt);

        // Unpublish then republish keeps the original publish timestamp.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/articles/{$article->id}/publish", ['published' => false]);
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/articles/{$article->id}/publish", ['published' => true]);

        $this->assertEquals($firstPublishedAt, $article->fresh()->published_at);
    }

    public function test_cover_url_is_returned_as_stored_absolute_url(): void
    {
        // The API returns the stored cover URL untouched (absolute), so it loads
        // in any client regardless of which API host the app is configured for.
        $article = app(ArticleService::class)->create([
            'title' => 'Cover', 'body' => '<p>a</p>', 'status' => 'published',
            'cover_image_url' => 'https://cdn.example.com/media/y.jpg',
        ]);

        $this->getJson("/api/v1/articles/{$article->slug}")
            ->assertJsonPath('data.cover_image_url', 'https://cdn.example.com/media/y.jpg');
        $this->getJson('/api/v1/articles')
            ->assertJsonFragment(['cover_image_url' => 'https://cdn.example.com/media/y.jpg']);
    }

    public function test_non_admin_cannot_create_articles(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/articles', ['title' => 'x', 'body' => '<p>a</p>'])
            ->assertForbidden();
    }
}
