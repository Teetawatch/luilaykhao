<?php

namespace Tests\Feature;

use App\Services\TripMemberLocationService;
use Tests\Support\InMemoryRedis;

/**
 * ทุกเทสต์ของ [TripMemberLocationTest] อีกรอบ ตอน Redis ปกติ — ที่เก็บหลักของตำแหน่ง
 * สมาชิกทริป เทสต์ที่ผูกกับแถวในตารางถูกเขียนใหม่ให้ดูที่ Redis แทน
 */
class TripMemberLocationRedisTest extends TripMemberLocationTest
{
    protected function makeRedis(): InMemoryRedis
    {
        return new InMemoryRedis;
    }

    /** @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        return collect($this->redis->data[TripMemberLocationService::redisKey($this->schedule->id)] ?? [])
            ->map(fn (string $json) => json_decode($json, true))
            ->all();
    }

    public function test_repeated_updates_overwrite_instead_of_piling_up(): void
    {
        $this->share($this->owner, lat: 19.39);
        $this->share($this->owner, lat: 19.40);

        // ไม่แตะตารางเลย และใน Redis มีหนึ่งช่องต่อคน
        $this->assertDatabaseCount('trip_member_locations', 0);
        $this->assertSame([(string) $this->owner->id], array_map('strval', array_keys($this->stored())));
        $this->assertEqualsWithDelta(19.40, $this->stored()[$this->owner->id]['latitude'], 0.0001);
        $this->assertGreaterThan(0, $this->redis->ttl(TripMemberLocationService::redisKey($this->schedule->id)));
    }

    public function test_stopping_deletes_the_row_and_tells_the_others(): void
    {
        $this->share($this->owner);
        $this->share($this->friend);

        parent::test_stopping_deletes_the_row_and_tells_the_others();

        // ปิดแล้วต้องหายจริงจาก Redis ด้วย ไม่ใช่แค่ซ่อนตอนอ่าน
        $this->assertArrayNotHasKey($this->owner->id, $this->stored());
        $this->assertArrayHasKey($this->friend->id, $this->stored());
    }

    public function test_stale_pins_are_not_handed_out(): void
    {
        $this->share($this->owner);

        $this->travel(TripMemberLocationService::STALE_MINUTES + 1)->minutes();

        $this->actingAs($this->friend)
            ->getJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertOk()
            ->assertJsonPath('data.members', []);

        // หมุดที่เก่าเกินถูกลบทิ้งจาก Redis ตอนอ่าน ไม่ค้างจนจบทริป
        $this->assertSame([], $this->stored());
    }

    public function test_a_pin_written_while_redis_was_down_is_still_removed_on_stop(): void
    {
        $this->redis->down = true;
        $this->share($this->owner);
        $this->assertDatabaseCount('trip_member_locations', 1);

        $this->redis->down = false;
        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertOk();

        $this->assertDatabaseCount('trip_member_locations', 0);
    }

    public function test_the_payload_matches_the_database_path_field_for_field(): void
    {
        $this->freezeTime();

        $this->actingAs($this->friend)->postJson("/api/v1/schedules/{$this->schedule->id}/live-location", [
            'latitude' => 19.39, 'longitude' => 98.89, 'accuracy_m' => 12.5, 'heading' => 90,
            'speed_kmh' => 3.2, 'altitude_m' => 2100, 'battery_level' => 64,
        ])->assertOk();

        $viaRedis = $this->actingAs($this->owner)
            ->getJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->json('data.members.0');

        $this->redis->down = true;
        $this->actingAs($this->friend)->postJson("/api/v1/schedules/{$this->schedule->id}/live-location", [
            'latitude' => 19.39, 'longitude' => 98.89, 'accuracy_m' => 12.5, 'heading' => 90,
            'speed_kmh' => 3.2, 'altitude_m' => 2100, 'battery_level' => 64,
        ])->assertOk();
        $viaDatabase = $this->actingAs($this->owner)
            ->getJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->json('data.members.0');

        $this->assertSame($viaDatabase, $viaRedis);
    }
}
