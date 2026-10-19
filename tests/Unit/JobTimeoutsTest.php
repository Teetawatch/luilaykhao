<?php

namespace Tests\Unit;

use App\Jobs\DeleteMediaFilesJob;
use Tests\TestCase;

/**
 * A job that runs longer than the connection's retry_after is handed to a second
 * worker while the first is still on it. Each hand-over counts as an attempt, so
 * the job dies with MaxAttemptsExceededException even though nothing inside it
 * failed — DeleteMediaFilesJob showed up in Horizon as "Attempts: 4" that way.
 */
class JobTimeoutsTest extends TestCase
{
    /** @return array<string, int> job class => its $timeout */
    private function jobTimeouts(): array
    {
        $timeouts = [];

        foreach (glob(app_path('Jobs/*.php')) as $path) {
            $class = 'App\\Jobs\\'.basename($path, '.php');
            if (! class_exists($class) || ! property_exists($class, 'timeout')) {
                continue;
            }

            $timeout = (new \ReflectionClass($class))->getProperty('timeout')->getDefaultValue();
            if (is_int($timeout)) {
                $timeouts[$class] = $timeout;
            }
        }

        return $timeouts;
    }

    public function test_the_scan_finds_jobs_with_a_timeout(): void
    {
        $this->assertArrayHasKey(DeleteMediaFilesJob::class, $this->jobTimeouts());
    }

    public function test_every_job_finishes_before_redis_hands_it_to_another_worker(): void
    {
        // Horizon works every queue through the `redis` connection (config/horizon.php)
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($this->jobTimeouts() as $class => $timeout) {
            $this->assertLessThan(
                $retryAfter,
                $timeout,
                "{$class} may run {$timeout}s but Redis re-delivers it after {$retryAfter}s — raise retry_after in config/queue.php.",
            );
        }

        $this->assertLessThan($retryAfter, (int) config('horizon.defaults.supervisor-1.timeout'));
    }
}
