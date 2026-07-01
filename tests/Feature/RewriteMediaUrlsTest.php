<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `media:rewrite-urls` swaps absolute {APP_URL}/storage/… media URLs stored in
 * the DB for their R2 equivalent, across string, JSON-array and HTML columns.
 */
class RewriteMediaUrlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://luilaykhao.com');
        config()->set('filesystems.disks.r2.url', 'https://cdn.example.com');
    }

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Doi Trip',
            'slug' => 'doi-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ], $overrides));
    }

    public function test_rewrites_string_json_and_html_columns_to_r2(): void
    {
        $trip = $this->makeTrip([
            'cover_image' => 'https://luilaykhao.com/storage/media/1778433147_ApMQ3p1n.png',
            'gallery' => [
                'https://luilaykhao.com/storage/media/a.jpg',
                'https://luilaykhao.com/storage/media/b.jpg',
            ],
        ]);

        $article = Article::create([
            'title' => 'Guide',
            'slug' => 'guide',
            'excerpt' => 'x',
            'body' => '<p>Hi</p><img src="https://luilaykhao.com/storage/media/inline.png"> more',
            'cover_image_url' => 'https://luilaykhao.com/storage/media/cover.png',
        ]);

        $this->artisan('media:rewrite-urls')->assertSuccessful();

        $trip->refresh();
        $article->refresh();

        $this->assertSame('https://cdn.example.com/media/1778433147_ApMQ3p1n.png', $trip->cover_image);
        $this->assertSame([
            'https://cdn.example.com/media/a.jpg',
            'https://cdn.example.com/media/b.jpg',
        ], $trip->gallery);
        $this->assertStringContainsString('src="https://cdn.example.com/media/inline.png"', $article->body);
        $this->assertSame('https://cdn.example.com/media/cover.png', $article->cover_image_url);
    }

    public function test_leaves_foreign_and_already_migrated_urls_untouched(): void
    {
        $trip = $this->makeTrip([
            'cover_image' => 'https://lh3.googleusercontent.com/a/photo.jpg', // external
            'thumbnail_image' => 'https://cdn.example.com/media/already.png',  // already R2
        ]);

        $this->artisan('media:rewrite-urls')->assertSuccessful();

        $trip->refresh();
        $this->assertSame('https://lh3.googleusercontent.com/a/photo.jpg', $trip->cover_image);
        $this->assertSame('https://cdn.example.com/media/already.png', $trip->thumbnail_image);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $trip = $this->makeTrip([
            'cover_image' => 'https://luilaykhao.com/storage/media/keep.png',
        ]);

        $this->artisan('media:rewrite-urls --dry-run')->assertSuccessful();

        $this->assertSame('https://luilaykhao.com/storage/media/keep.png', $trip->fresh()->cover_image);
    }

    public function test_aborts_when_r2_url_not_configured(): void
    {
        config()->set('filesystems.disks.r2.url', null);

        $this->artisan('media:rewrite-urls')->assertFailed();
    }
}
