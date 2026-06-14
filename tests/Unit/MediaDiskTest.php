<?php

namespace Tests\Unit;

use App\Support\MediaDisk;
use Tests\TestCase;

class MediaDiskTest extends TestCase
{
    public function test_falls_back_to_public_when_r2_is_not_configured(): void
    {
        config(['filesystems.default' => 'local']);
        config(['filesystems.disks.r2.bucket' => null]);

        $this->assertSame('public', MediaDisk::name());
    }

    public function test_uses_r2_when_a_bucket_is_configured(): void
    {
        config(['filesystems.default' => 'local']);
        config(['filesystems.disks.r2.bucket' => 'luilaykhao']);

        $this->assertSame('r2', MediaDisk::name());
    }

    public function test_uses_r2_when_it_is_the_default_disk(): void
    {
        config(['filesystems.default' => 'r2']);
        config(['filesystems.disks.r2.bucket' => null]);

        $this->assertSame('r2', MediaDisk::name());
    }

    public function test_url_returns_null_for_empty_paths(): void
    {
        $this->assertNull(MediaDisk::url(null));
        $this->assertNull(MediaDisk::url(''));
    }

    public function test_slip_disk_is_private_bucket_when_configured(): void
    {
        config(['filesystems.disks.r2_private.bucket' => 'luilaykhao-private']);
        $this->assertSame('r2_private', MediaDisk::slipDisk());

        config(['filesystems.disks.r2_private.bucket' => null]);
        $this->assertSame('local', MediaDisk::slipDisk());
    }

    public function test_slip_url_is_null_for_empty_path(): void
    {
        $this->assertNull(MediaDisk::slipUrl(null));
        $this->assertNull(MediaDisk::slipUrl(''));
    }

    public function test_slip_url_falls_back_to_a_signed_route_for_local_disk(): void
    {
        // Local disk can't mint presigned URLs, so we expect a signed app route.
        config(['filesystems.disks.r2_private.bucket' => null]);

        $url = MediaDisk::slipUrl('slips/2026/06/abc.jpg');

        $this->assertIsString($url);
        $this->assertStringContainsString('/slips/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_url_passes_through_already_absolute_urls(): void
    {
        $absolute = 'https://media.luilaykhao.com/reviews/abc.jpg';

        $this->assertSame($absolute, MediaDisk::url($absolute));
    }
}
