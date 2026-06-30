<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\GroupPlanService;
use App\Services\SeatLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupPlanTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(int $seats = 10): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Pha Daeng Group Hike',
            'slug' => 'pha-daeng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => $seats,
            'price_per_person' => 1800,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => $seats,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_host_can_create_a_group_and_share_invite_code(): void
    {
        $schedule = $this->makeSchedule();
        $host = User::factory()->create();

        $response = $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/group-plans", [
                'seat_count' => 4,
                'name' => 'ทริปเพื่อนซี้',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.is_host', true)
            ->assertJsonPath('data.seat_count', 4);

        $code = $response->json('data.invite_code');
        $this->assertNotEmpty($code);
        // Host is auto-added as a member.
        $this->assertCount(1, $response->json('data.members'));
    }

    public function test_full_flow_friend_joins_claims_seat_and_host_checks_out(): void
    {
        $schedule = $this->makeSchedule();
        $host = User::factory()->create(['name' => 'Host Hugo']);
        $friend = User::factory()->create(['name' => 'Friend Fae']);

        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, 'Squad');
        $code = $plan->invite_code;

        // Friend joins.
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/join")
            ->assertOk()
            ->assertJsonPath('data.members.1.user_id', $friend->id);

        // Both claim seats + fill passenger details.
        $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'A1',
                'name' => 'Host Hugo',
                'phone' => '0810000001',
            ])
            ->assertOk();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'A2',
                'name' => 'Friend Fae',
                'phone' => '0810000002',
            ])
            ->assertOk()
            ->assertJsonPath('data.claimed_seat_ids', ['A1', 'A2']);

        // Host checks out -> single booking with both passengers + seats.
        $response = $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/checkout")
            ->assertCreated();

        $ref = $response->json('data.booking_ref');
        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        $this->assertTrue((bool) $booking->is_group);
        $this->assertSame($host->id, $booking->user_id);
        $this->assertCount(2, $booking->passengers);
        $this->assertCount(2, $booking->seats);

        $this->assertSame('booked', $plan->fresh()->status);
        $this->assertSame($booking->id, $plan->fresh()->booking_id);
    }

    public function test_host_can_check_out_even_when_the_redis_seat_lock_has_lapsed(): void
    {
        // Seats are claimed and held durably in group_plan_members; the Redis soft-lock is
        // advisory and may expire or be evicted (shared cache/queue store) while the group
        // gathers. Checkout must not fail with "ที่นั่งไม่ได้ถูกล็อคโดยคุณ" when that happens.
        $this->mock(SeatLockService::class, function ($mock) {
            $mock->shouldReceive('lock')->andReturn(['locked' => true, 'expires_at' => now()->toISOString()]);
            $mock->shouldReceive('forceUnlock')->andReturnNull();
            $mock->shouldReceive('unlock')->andReturnTrue();
            // The lock has lapsed by the time the host pays.
            $mock->shouldReceive('isLockedByUser')->andReturnFalse();
        });

        $schedule = $this->makeSchedule();
        $host = User::factory()->create(['name' => 'Host Hugo']);
        $friend = User::factory()->create(['name' => 'Friend Fae']);

        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, 'Squad');
        $code = $plan->invite_code;

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/join")
            ->assertOk();

        $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'A1', 'name' => 'Host Hugo', 'phone' => '0810000001',
            ])->assertOk();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'A2', 'name' => 'Friend Fae', 'phone' => '0810000002',
            ])->assertOk();

        $response = $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/checkout")
            ->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertCount(2, $booking->seats);
        $this->assertSame('booked', $plan->fresh()->status);
    }

    public function test_two_members_cannot_claim_the_same_seat(): void
    {
        $schedule = $this->makeSchedule();
        $host = User::factory()->create();
        $friend = User::factory()->create();

        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, null);
        $code = $plan->invite_code;

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/join")->assertOk();

        $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'A1', 'name' => 'Host',
            ])->assertOk();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'A1', 'name' => 'Friend',
            ])
            ->assertStatus(422);
    }

    public function test_only_host_can_checkout(): void
    {
        $schedule = $this->makeSchedule();
        $host = User::factory()->create();
        $friend = User::factory()->create();

        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, null);
        $code = $plan->invite_code;

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/join")->assertOk();
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'B1', 'name' => 'Friend',
            ])->assertOk();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/checkout")
            ->assertStatus(422);
    }

    public function test_expired_plan_is_swept_and_closed(): void
    {
        $schedule = $this->makeSchedule();
        $host = User::factory()->create();
        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, null);

        $plan->update(['expires_at' => now()->subMinute()]);

        $expired = app(GroupPlanService::class)->expireStale();
        $this->assertSame(1, $expired);
        $this->assertSame('expired', $plan->fresh()->status);
    }

    public function test_non_member_cannot_view_plan_via_show_requires_auth(): void
    {
        $schedule = $this->makeSchedule();
        $host = User::factory()->create();
        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, null);

        // Unauthenticated request is rejected by sanctum middleware.
        $this->getJson("/api/v1/group-plans/{$plan->invite_code}")
            ->assertStatus(401);
    }

    public function test_plan_payload_exposes_schedule_pickup_points(): void
    {
        $schedule = $this->makeSchedule();
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'north',
            'region_label' => 'ภาคเหนือ',
            'pickup_location' => 'หน้า มช.',
            'price' => 2500,
            'sort_order' => 1,
        ]);
        $host = User::factory()->create();
        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, null);

        $this->actingAs($host, 'sanctum')
            ->getJson("/api/v1/group-plans/{$plan->invite_code}")
            ->assertOk()
            ->assertJsonPath('data.schedule.pickup_points.0.region', 'north')
            ->assertJsonPath('data.schedule.pickup_points.0.region_label', 'ภาคเหนือ')
            ->assertJsonPath('data.schedule.pickup_points.0.pickup_location', 'หน้า มช.')
            ->assertJsonPath('data.schedule.pickup_points.0.price', 2500);
    }

    public function test_mine_lists_hosted_and_joined_plans(): void
    {
        $schedule = $this->makeSchedule();
        $host = User::factory()->create();
        $friend = User::factory()->create();

        $hosted = app(GroupPlanService::class)->create($host, $schedule, 4, 'Hosted');
        $other = app(GroupPlanService::class)->create($friend, $this->makeSchedule(), 4, 'Friend trip');

        // Host joins the friend's plan too.
        $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$other->invite_code}/join")->assertOk();

        $codes = collect(
            $this->actingAs($host, 'sanctum')
                ->getJson('/api/v1/group-plans/mine')
                ->assertOk()
                ->json('data')
        )->pluck('invite_code');

        $this->assertTrue($codes->contains($hosted->invite_code));
        $this->assertTrue($codes->contains($other->invite_code));
    }

    public function test_checkout_applies_selected_pickup_point_price(): void
    {
        $schedule = $this->makeSchedule();
        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'north',
            'region_label' => 'ภาคเหนือ',
            'pickup_location' => 'หน้า มช.',
            'price' => 2500, // overrides the 1800 base price
            'sort_order' => 1,
        ]);
        $host = User::factory()->create();
        $plan = app(GroupPlanService::class)->create($host, $schedule, 4, null);
        $code = $plan->invite_code;

        $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/claim-seat", [
                'seat_id' => 'A1', 'name' => 'Host',
            ])->assertOk();

        $response = $this->actingAs($host, 'sanctum')
            ->postJson("/api/v1/group-plans/{$code}/checkout", [
                'pickup_point_id' => $point->id,
                'pickup_region' => 'north',
            ])
            ->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertSame($point->id, $booking->pickup_point_id);
        $this->assertSame(2500.0, (float) $booking->total_amount);
    }
}
