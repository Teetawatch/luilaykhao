<?php

namespace Tests\Feature;

use App\Models\ArticleCategory;
use App\Models\Trip;
use App\Services\ArticleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The blog pages are the SEO surface — they must server-render real HTML
 * (title, body, Article JSON-LD, canonical) with no JS, and never leak drafts.
 */
class BlogPageTest extends TestCase
{
    use RefreshDatabase;

    private function publish(array $data)
    {
        return app(ArticleService::class)->create(array_merge([
            'status' => 'published',
            'body' => '<p>เนื้อหาบทความตัวอย่าง</p>',
        ], $data));
    }

    /** Browsers/Googlebot always percent-encode the Thai slug in the path. */
    private function blogUrl(string $slug): string
    {
        return '/blog/'.rawurlencode($slug);
    }

    public function test_blog_index_renders_published_articles_server_side(): void
    {
        $this->publish(['title' => 'เตรียมตัวเดินป่าภูกระดึง', 'excerpt' => 'คู่มือ']);

        $res = $this->get('/blog');

        $res->assertOk()
            ->assertSee('เตรียมตัวเดินป่าภูกระดึง', false)
            ->assertSee('บทความและคำแนะนำเที่ยว', false);
    }

    public function test_article_page_has_full_content_and_seo_markup(): void
    {
        $cat = ArticleCategory::create(['name' => 'เดินป่า', 'slug' => 'trekking']);
        $trip = Trip::create([
            'title' => 'ทริปภูกระดึง', 'slug' => 'phu-kradueng', 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 20, 'price_per_person' => 2500, 'status' => 'active',
        ]);
        $article = $this->publish([
            'title' => 'รีวิวเดินป่าภูกระดึง',
            'body' => '<p>เนื้อหาเต็มของบทความ</p>',
            'category_id' => $cat->id,
            'trip_ids' => [$trip->id],
        ]);

        $res = $this->get($this->blogUrl($article->slug));

        $res->assertOk()
            ->assertSee('รีวิวเดินป่าภูกระดึง', false)        // h1
            ->assertSee('เนื้อหาเต็มของบทความ', false)         // body rendered server-side
            ->assertSee('application/ld+json', false)          // JSON-LD present
            ->assertSee('"@type":"Article"', false)
            ->assertSee('rel="canonical"', false)
            ->assertSee(url('/blog/'.$article->slug), false)   // canonical url
            ->assertSee(url('/trips/'.$trip->slug), false);    // funnel link into SPA
    }

    public function test_draft_article_is_not_reachable(): void
    {
        $draft = app(ArticleService::class)->create([
            'title' => 'ฉบับร่าง', 'body' => '<p>x</p>', 'status' => 'draft',
        ]);

        $this->get($this->blogUrl($draft->slug))->assertNotFound();
    }

    public function test_category_archive_lists_only_its_articles(): void
    {
        $cat = ArticleCategory::create(['name' => 'ทะเล', 'slug' => 'sea']);
        $this->publish(['title' => 'ดำน้ำเกาะเต่า', 'category_id' => $cat->id]);
        $this->publish(['title' => 'เดินป่าดอยหลวง']);

        $res = $this->get('/blog/category/sea');

        $res->assertOk()
            ->assertSee('ดำน้ำเกาะเต่า', false)
            ->assertDontSee('เดินป่าดอยหลวง', false);
    }

    public function test_sitemap_includes_published_articles(): void
    {
        $article = $this->publish(['title' => 'บทความใน sitemap']);

        $res = $this->get('/sitemap.xml');

        $res->assertOk()->assertSee(url('/blog/'.$article->slug), false);
    }
}
