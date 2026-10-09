<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Horizon only works the queues it is told about. A job dispatched onto any other
 * queue is accepted by Redis and then sits there forever with no error anywhere —
 * the reminder and SMS-retry jobs did exactly that for three months, because the
 * commands sent them to `reminders` / `sms` while Horizon only watched `default`.
 */
class HorizonQueuesTest extends TestCase
{
    /** @return string[] queue names the app dispatches to */
    private function dispatchedQueues(): array
    {
        $fromCode = collect(glob(app_path('{,*/,*/*/,*/*/*/}*.php'), GLOB_BRACE))
            ->flatMap(function ($path) {
                preg_match_all("/onQueue\\(\\s*'([^']+)'/", file_get_contents($path), $m);

                return $m[1];
            });

        // คิวของ connection redis-* ใน config/queue.php ใช้ Redis ตัวเดียวกัน
        $fromConfig = collect(config('queue.connections'))
            ->filter(fn ($c) => ($c['driver'] ?? null) === 'redis')
            ->pluck('queue');

        return $fromCode->merge($fromConfig)->unique()->values()->all();
    }

    public function test_the_scan_finds_the_known_queues(): void
    {
        // กันไม่ให้ glob/regex จับอะไรไม่ได้เลยแล้วผ่านแบบว่างเปล่า
        $this->assertContains('sms', $this->dispatchedQueues());
        $this->assertContains('reminders', $this->dispatchedQueues());
    }

    public function test_horizon_works_every_queue_the_app_dispatches_to(): void
    {
        $watched = config('horizon.defaults.supervisor-1.queue');

        foreach ($this->dispatchedQueues() as $queue) {
            $this->assertContains($queue, $watched, "No Horizon supervisor works the '{$queue}' queue — its jobs would never run.");
        }
    }
}
