<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ScheduleRallyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleRallyTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เดินป่าดอยอินทนนท์',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(11)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 4,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function bookOn(TripSchedule $schedule, User $user, string $status = 'confirmed'): Booking
    {
        return Booking::create([
            'booking_ref' => sprintf('LLK-20200101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    public function test_rally_is_active_for_an_underfilled_round_near_departure(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule(['booked_seats' => 6]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.seats_needed', 2)
            ->assertJsonPath('data.days_left', 10)
            ->assertJsonPath('data.status', TripSchedule::STATUS_ALMOST_READY);
    }

    public function test_guaranteed_round_needs_no_rally(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule(['booked_seats' => 8]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.reason', 'already_guaranteed');
    }

    public function test_round_far_out_is_not_rallied_yet(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(60)->toDateString(),
            'booked_seats' => 2,
        ]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.reason', 'too_early');
    }

    public function test_departed_round_is_not_rallied(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->subDays(2)->toDateString(),
            'booked_seats' => 3,
        ]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.reason', 'departed');
    }

    public function test_charter_round_is_never_rallied(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule(['is_charter' => true, 'booked_seats' => 2]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.reason', 'charter');
    }

    public function test_seats_needed_never_exceeds_seats_actually_available(): void
    {
        $user = User::factory()->create();
        // ขาด 7 ที่นั่งถึงจะการันตี แต่ขายได้อีกแค่ 2 ที่
        $schedule = $this->makeSchedule(['total_seats' => 3, 'booked_seats' => 1]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.seats_needed', 2)
            ->assertJsonPath('data.seats_available', 2);
    }

    public function test_full_round_below_guarantee_is_not_rallied(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule(['total_seats' => 4, 'booked_seats' => 4]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.reason', 'no_seats_left');
    }

    public function test_share_link_carries_the_inviters_referral_code(): void
    {
        $user = User::factory()->create(['referral_code' => 'abc123']);
        $schedule = $this->makeSchedule(['booked_seats' => 6]);
        $this->bookOn($schedule, $user);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk();

        $this->assertStringContainsString('ref=ABC123', $res->json('data.share_url'));
        $this->assertStringContainsString("schedule={$schedule->id}", $res->json('data.share_url'));
    }

    public function test_companion_on_the_booking_can_see_the_rally(): void
    {
        $owner = User::factory()->create();
        $companion = User::factory()->create();
        $schedule = $this->makeSchedule(['booked_seats' => 6]);
        $booking = $this->bookOn($schedule, $owner);

        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($companion, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', true);
    }

    public function test_someone_without_a_booking_cannot_see_the_rally(): void
    {
        $schedule = $this->makeSchedule(['booked_seats' => 6]);
        $this->bookOn($schedule, User::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertStatus(403);
    }

    public function test_cancelled_booking_does_not_grant_access(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule(['booked_seats' => 6]);
        $this->bookOn($schedule, $user, 'cancelled');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertStatus(403);
    }

    public function test_rally_window_boundary_is_inclusive(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')
                ->addDays(ScheduleRallyService::RALLY_WINDOW_DAYS)
                ->toDateString(),
            'booked_seats' => 6,
        ]);
        $this->bookOn($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/rally")
            ->assertOk()
            ->assertJsonPath('data.active', true);
    }
}
