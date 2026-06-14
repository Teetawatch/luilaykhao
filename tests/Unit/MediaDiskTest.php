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

    public function test_url_passes_through_already_absolute_urls(): void
    {
        $absolute = 'https://media.luilaykhao.com/reviews/abc.jpg';

        $this->assertSame($absolute, MediaDisk::url($absolute));
    }
}
