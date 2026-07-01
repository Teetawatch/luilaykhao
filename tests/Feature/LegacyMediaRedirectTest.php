<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The /storage/{path} route 301-redirects legacy local-media URLs to their R2
 * equivalent once the local files have been pruned. Only active when R2 is the
 * media disk; otherwise it must 404 (never loop back onto the local /storage).
 */
class LegacyMediaRedirectTest extends TestCase
{
    private function useR2Disk(): void
    {
        // Model R2 with a local driver so url() resolves without S3 creds. The
        // 'bucket' key is what flips App\Support\MediaDisk::name() to 'r2'.
        config()->set('filesystems.disks.r2', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/r2'),
            'url' => 'https://cdn.example.com',
            'bucket' => 'test-bucket',
        ]);
    }

    public function test_redirects_legacy_storage_url_to_r2_when_r2_is_active(): void
    {
        $this->useR2Disk();

        $this->get('/storage/media/1700000000_abcd1234.jpg')
            ->assertStatus(301)
            ->assertRedirect('https://cdn.example.com/media/1700000000_abcd1234.jpg');
    }

    public function test_redirect_preserves_nested_paths(): void
    {
        $this->useR2Disk();

        $this->get('/storage/reviews/2026/07/photo.webp')
            ->assertRedirect('https://cdn.example.com/reviews/2026/07/photo.webp');
    }

    public function test_returns_404_when_r2_is_not_the_media_disk(): void
    {
        // Default test config has no R2 bucket, so media disk is 'public'. The
        // route must not redirect (which would loop back onto /storage).
        config()->set('filesystems.disks.r2.bucket', null);

        $this->get('/storage/media/whatever.jpg')->assertNotFound();
    }
}
