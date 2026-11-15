<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Models\VehicleShareStop;
use App\Services\VehicleLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * "ตอนนี้รถอยู่ไหน / มีพิกัดตั้งแต่ X ไหม / ใครถือสิทธิ์แชร์" ตอบจาก Redis ก่อน
 * ตาราง vehicle_locations โตขึ้นทุกวัน — คำถามที่ถูกถามทุกนาที (การ์ดหน้าจอล็อก,
 * แจ้งเตือน ETA, งานตามสตาฟ) และทุกครั้งที่มือถือสตาฟส่งพิกัด ไม่ควรแตะตารางนั้น
 *
 * Redis ในเทสต์เป็นของปลอมในหน่วยความจำ — เครื่อง dev/CI ไม่จำเป็นต้องมี Redis
 */
class VehicleLocationRedisFirstTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $redis = [];

    protected function setUp(): void
    {
        parent::setUp();

        Redis::shouldReceive('get')->andReturnUsing(fn (string $key) => $this->redis[$key] ?? null);
        Redis::shouldReceive('setex')->andReturnUsing(function (string $key, int $ttl, string $value) {
            $this->redis[$key] = $value;

            return true;
        });
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create(['name' => 'รถตู้', 'type' => 'van', 'capacity' => 10, 'license_plate' => 'กข-1234']);
    }

    private function record(Vehicle $vehicle, int $minutesAgo, ?int $userId = null): VehicleLocation
    {
        return app(VehicleLocationService::class)->record($vehicle, [
            'latitude' => 13.7 + $minutesAgo / 1000,
            'longitude' => 100.5,
            'speed' => 40,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ], $userId);
    }

    /** จำนวน query ที่แตะตาราง vehicle_locations ระหว่าง [callback] */
    private function locationQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'vehicle_locations'))->count();
        DB::disableQueryLog();

        return $count;
    }

    public function test_latest_point_and_since_checks_come_from_redis_without_touching_the_table(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, minutesAgo: 3);
        $service = app(VehicleLocationService::class);

        $queries = $this->locationQueries(function () use ($service, $vehicle) {
            $latest = $service->latestPoint($vehicle->id);
            $this->assertEqualsWithDelta(13.703, $latest->latitude, 0.0001);
            $this->assertNotNull($service->latestPoint($vehicle->id, now()->subMinutes(10)));
            $this->assertSame(40.0, $service->latestCoordinates($vehicle->id)['speed']);
        });

        $this->assertSame(0, $queries);
    }

    public function test_a_stale_cache_is_confirmed_against_the_table_before_saying_no(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, minutesAgo: 30);
        $service = app(VehicleLocationService::class);

        // แคชเก่ากว่ากรอบ — อาจเป็นเพราะ Redis สะดุดตอนเขียนจุดใหม่ ต้องถามตารางยืนยัน
        $queries = $this->locationQueries(fn () => $this->assertNull($service->latestPoint($vehicle->id, now()->subMinutes(10))));
        $this->assertSame(1, $queries);
    }

    public function test_an_old_point_arriving_late_does_not_replace_the_newest_one(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, minutesAgo: 1);
        // คิวออฟไลน์ของแอปส่งจุดเก่าตามมาทีหลัง
        $this->record($vehicle, minutesAgo: 15);

        $latest = app(VehicleLocationService::class)->latestPoint($vehicle->id);
        $this->assertTrue($latest->recorded_at->gt(now()->subMinutes(2)));
        $this->assertSame(VehicleLocation::orderByDesc('recorded_at')->value('id'), $latest->id);
    }

    public function test_the_sharer_claim_is_answered_from_redis_on_every_ping(): void
    {
        $vehicle = $this->vehicle();
        $staff = User::factory()->create();
        $this->record($vehicle, minutesAgo: 1, userId: $staff->id);
        $service = app(VehicleLocationService::class);

        $queries = $this->locationQueries(fn () => $this->assertSame($staff->id, $service->currentSharerId($vehicle->id)));
        $this->assertSame(0, $queries);
    }

    public function test_the_sharer_claim_falls_back_to_the_table_when_redis_is_empty(): void
    {
        $vehicle = $this->vehicle();
        $staff = User::factory()->create();
        $this->record($vehicle, minutesAgo: 1, userId: $staff->id);
        $this->redis = [];

        $this->assertSame($staff->id, app(VehicleLocationService::class)->currentSharerId($vehicle->id));
    }

    public function test_resuming_after_a_stop_is_seen_from_the_cached_point(): void
    {
        $vehicle = $this->vehicle();
        $staff = User::factory()->create();
        $before = $this->record($vehicle, minutesAgo: 10, userId: $staff->id);
        $stop = (new VehicleShareStop)->forceFill([
            'vehicle_id' => $vehicle->id, 'user_id' => $staff->id, 'reason' => 'arrived',
            'last_location_id' => $before->id, 'stopped_at' => now()->subMinutes(10),
        ]);
        $this->record($vehicle, minutesAgo: 0, userId: $staff->id);

        $service = app(VehicleLocationService::class);
        $cached = (new \ReflectionMethod($service, 'cachedPoint'))->invoke($service, $vehicle->id);

        $this->assertGreaterThan($stop->last_location_id, $cached->id);
        $this->assertTrue($cached->created_at->gt($stop->stopped_at->copy()->addMinute()));
    }

    public function test_the_public_vehicle_list_no_longer_scans_the_location_table(): void
    {
        $vehicle = $this->vehicle();
        $this->record($vehicle, minutesAgo: 1);

        $queries = $this->locationQueries(fn () => $this->getJson('/api/v1/vehicles')->assertOk());
        $this->assertSame(0, $queries);
    }

    public function test_a_gps_batch_is_written_in_one_insert_and_rejects_unknown_vehicles(): void
    {
        $vehicle = $this->vehicle();
        $points = collect(range(1, 20))->map(fn ($i) => [
            'vehicle_id' => $vehicle->id,
            'latitude' => 13.7 + $i / 1000,
            'longitude' => 100.5,
            'recorded_at' => now()->subMinutes(21 - $i)->toIso8601String(),
        ])->all();

        $queries = $this->locationQueries(fn () => $this->postJson('/api/v1/tracking/batch', ['locations' => $points])
            ->assertOk()
            ->assertJsonPath('data.inserted', 20));

        $this->assertSame(1, $queries);
        $this->assertSame(20, VehicleLocation::count());
        // แคชได้จุดล่าสุดของชุด
        $this->assertEqualsWithDelta(13.720, app(VehicleLocationService::class)->latestPoint($vehicle->id)->latitude, 0.0001);

        $this->postJson('/api/v1/tracking/batch', ['locations' => [
            ['vehicle_id' => 999999, 'latitude' => 13.7, 'longitude' => 100.5],
        ]])->assertStatus(422)->assertJsonValidationErrors('locations.0.vehicle_id', 'errors');
        $this->assertSame(20, VehicleLocation::count());
    }
}
