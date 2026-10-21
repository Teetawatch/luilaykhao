<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

class ChatTripLiveTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
    }

    public function test_trip_day_room_offers_where_is_the_van_with_lock_screen_copy(): void
    {
        // departs_at เก็บนาฬิกาไทยในคอลัมน์ UTC — อีก 3 ชม. ตามเวลาไทย
        $departs = now('Asia/Bangkok')->addHours(3)->format('Y-m-d H:i:s');
        $schedule = $this->tripSchedule(['departs_at' => $departs]);
        [$owner] = $this->party($schedule, ['เอ']);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'room'))
            ->assertOk()
            ->assertJsonPath('data.trip_live', true);

        $live = $this->actingAs($owner, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'trip-info'))
            ->assertOk()
            ->json('data.live');

        $this->assertNotNull($live);
        $this->assertNotEmpty($live['headline']);
        $this->assertFalse($live['can_track']); // รอบนี้ยังไม่มีรถ
    }

    public function test_far_away_room_has_no_live_state(): void
    {
        $schedule = $this->tripSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
        ]);
        [$owner] = $this->party($schedule, ['เอ']);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'room'))
            ->assertJsonPath('data.trip_live', false);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'trip-info'))
            ->assertJsonPath('data.live', null);
    }
}
