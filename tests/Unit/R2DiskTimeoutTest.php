<?php

namespace Tests\Unit;

use App\Jobs\MirrorSocialAvatarJob;
use Tests\TestCase;

/**
 * The AWS SDK has no timeout unless told: a stalled connection to R2 once held a
 * worker until Horizon killed MirrorSocialAvatarJob at 60 s, twice in a row.
 */
class R2DiskTimeoutTest extends TestCase
{
    public function test_r2_disks_give_up_before_horizon_kills_the_job(): void
    {
        $horizon = config('horizon.defaults.supervisor-1.timeout');

        foreach (['r2', 'r2_private'] as $disk) {
            $http = config("filesystems.disks.{$disk}.http");

            $this->assertGreaterThan(0, $http['connect_timeout'] ?? 0, "{$disk} has no connect timeout");
            $this->assertGreaterThan(0, $http['timeout'] ?? 0, "{$disk} has no request timeout");
            $this->assertLessThan($horizon, $http['timeout'], "{$disk} would outlive Horizon's {$horizon}s timeout");
        }
    }

    public function test_avatar_mirror_times_out_before_the_supervisor(): void
    {
        $job = new MirrorSocialAvatarJob(1, 'https://example.com/a.jpg');

        $this->assertLessThan(config('horizon.defaults.supervisor-1.timeout'), $job->timeout);
    }
}
