<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Services\SeatLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * "ที่นั่งที่ล็อกไว้" ของผู้ใช้อ่านจากดัชนีต่อผู้ใช้ (seat_lock_user:{id}) แทน KEYS
 *
 * ต้องมี Redis จริง — ชี้ไปที่ TEST_REDIS_HOST:TEST_REDIS_PORT (ค่าเริ่มต้น 127.0.0.1:6390)
 * ฐานข้อมูลเบอร์ 15 และล้างทิ้งหลังจบ ไม่มี Redis = ข้ามทั้งคลาส
 */
class SeatLockIndexTest extends TestCase
{
    use RefreshDatabase;

    private SeatLockService $locks;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.redis.client' => 'predis',
            'database.redis.options.prefix' => 'seatlocktest:',
            'database.redis.default' => [
                'host' => env('TEST_REDIS_HOST', '127.0.0.1'),
                'port' => (int) env('TEST_REDIS_PORT', 6390),
                'password' => null,
                'database' => 15,
                'read_timeout' => 1,
                'timeout' => 1,
            ],
        ]);
        app('redis')->purge('default');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstances();

        try {
            Redis::ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis for tests is not reachable.');
        }

        Redis::flushdb();
        $this->locks = app(SeatLockService::class);
    }

    protected function tearDown(): void
    {
        try {
            Redis::flushdb();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Doi Trip '.uniqid(),
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(10)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_active_locks_come_from_the_user_index(): void
    {
        $a = $this->schedule();
        $b = $this->schedule();

        $this->locks->lockMultiple($a->id, ['A2', 'A1'], 7, ['pickup_point_id' => 3], 600);
        $this->locks->lock($b->id, 'B1', 7, [], 900, 4);
        $this->locks->lock($a->id, 'C1', 8, [], 600);

        $mine = collect($this->locks->activeLocksForUser(7))->keyBy(fn ($l) => $l['schedule_id'].':'.($l['vehicle_option_id'] ?? 0));

        $this->assertCount(2, $mine);
        $this->assertSame(['A1', 'A2'], $mine["{$a->id}:0"]['seat_ids']);
        $this->assertSame(3, $mine["{$a->id}:0"]['pickup_point_id']);
        $this->assertSame(['B1'], $mine["{$b->id}:4"]['seat_ids']);
        $this->assertSame($b->trip->slug, $mine["{$b->id}:4"]['trip']['slug']);

        $this->assertSame(['C1'], $this->locks->activeLocksForUser(8)[0]['seat_ids']);
        $this->assertSame([], $this->locks->activeLocksForUser(9));
    }

    public function test_unlocked_and_expired_seats_drop_out_of_the_index(): void
    {
        $s = $this->schedule();
        $this->locks->lockMultiple($s->id, ['A1', 'A2', 'A3'], 7, [], 600);

        $this->locks->unlock($s->id, 'A1', 7);
        $this->locks->forceUnlock($s->id, 'A2');
        // ล็อกหมดอายุไปเอง (จำลองด้วยการลบคีย์) — ดัชนียังมีอยู่ ต้องถูกกวาดตอนอ่าน
        Redis::del("seat_lock:{$s->id}:0:A3");

        $this->assertSame([], $this->locks->activeLocksForUser(7));
        $this->assertSame([], Redis::smembers('seat_lock_user:7'));
    }

    public function test_a_seat_taken_over_by_someone_else_is_not_reported(): void
    {
        $s = $this->schedule();
        $this->locks->lock($s->id, 'A1', 7, [], 600);
        // ล็อกของ 7 หมด แล้วคนอื่นล็อกที่นั่งเดิมต่อ
        Redis::del("seat_lock:{$s->id}:0:A1");
        $this->locks->lock($s->id, 'A1', 8, [], 600);

        $this->assertSame([], $this->locks->activeLocksForUser(7));
        $this->assertSame(['A1'], $this->locks->activeLocksForUser(8)[0]['seat_ids']);
    }

    public function test_index_outlives_the_longest_lock(): void
    {
        $s = $this->schedule();
        $this->locks->lock($s->id, 'A1', 7, [], 1800);
        $this->locks->lock($s->id, 'A2', 7, [], 600);

        $this->assertGreaterThan(1800, (int) Redis::ttl('seat_lock_user:7'));
    }

    public function test_unlock_active_for_user_releases_through_the_index(): void
    {
        $s = $this->schedule();
        $this->locks->lockMultiple($s->id, ['A1', 'A2'], 7, [], 600);

        $result = $this->locks->unlockActiveForUser($s->id, 7);

        $this->assertSame(2, $result['unlocked_count']);
        $this->assertSame(0, (int) Redis::exists("seat_lock:{$s->id}:0:A1"));
        $this->assertSame([], $this->locks->activeLocksForUser(7));
    }

    public function test_reindex_picks_up_locks_made_before_the_index_existed(): void
    {
        $s = $this->schedule();
        $this->locks->lockMultiple($s->id, ['A1', 'A2'], 7, [], 600);
        Redis::del('seat_lock_user:7');
        $this->assertSame([], $this->locks->activeLocksForUser(7));

        $this->assertSame(2, $this->locks->reindex());

        $this->assertSame(['A1', 'A2'], $this->locks->activeLocksForUser(7)[0]['seat_ids']);
        $this->artisan('seat-locks:reindex')->assertSuccessful();
    }
}
