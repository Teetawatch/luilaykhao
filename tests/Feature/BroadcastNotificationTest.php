<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastNotificationJob;
use App\Models\BroadcastDispatch;
use App\Models\FcmToken;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BroadcastNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $status = 'active'): Trip
    {
        return Trip::create([
            'title' => 'Pha Daeng Sunrise',
            'slug' => 'pha-daeng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 1990,
            'status' => $status,
        ]);
    }

    private function makeSchedule(Trip $trip, int $total, int $booked): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => $total,
            'booked_seats' => $booked,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function userWithToken(bool $marketing = true): User
    {
        $user = User::factory()->create(['marketing_push_enabled' => $marketing]);
        FcmToken::create([
            'user_id' => $user->id,
            'token' => 'tok-'.uniqid(),
            'platform' => 'android',
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_publishing_a_trip_broadcasts_once(): void
    {
        Queue::fake();

        $trip = $this->makeTrip(status: 'inactive'); // not announced yet
        Queue::assertNotPushed(SendBroadcastNotificationJob::class);

        $trip->update(['status' => 'active']); // published → announce
        Queue::assertPushed(SendBroadcastNotificationJob::class, 1);
        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'new_trip',
            'dedupe_key' => "new_trip:{$trip->id}",
        ]);

        // Toggling status off and on again must not re-announce.
        $trip->update(['status' => 'inactive']);
        $trip->update(['status' => 'active']);
        Queue::assertPushed(SendBroadcastNotificationJob::class, 1);
    }

    public function test_fanout_reaches_only_opted_in_users_with_tokens(): void
    {
        $reachable = $this->userWithToken(marketing: true);
        $optedOut = $this->userWithToken(marketing: false);
        $noToken = User::factory()->create(['marketing_push_enabled' => true]);

        (new SendBroadcastNotificationJob('new_trip', 'ทริปใหม่', 'มาแล้ว', []))->handle();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $reachable->id,
            'type' => 'new_trip',
        ]);
        $this->assertSame(0, SmartNotification::where('user_id', $optedOut->id)->count());
        $this->assertSame(0, SmartNotification::where('user_id', $noToken->id)->count());
    }

    public function test_low_seats_sweep_blasts_once_per_round(): void
    {
        Queue::fake();

        $trip = $this->makeTrip();
        $low = $this->makeSchedule($trip, total: 10, booked: 8);  // 2 left → blast
        $this->makeSchedule($trip, total: 10, booked: 4);          // 6 left → quiet

        app(BroadcastNotificationService::class)->sweepLowSeats();

        Queue::assertPushed(
            SendBroadcastNotificationJob::class,
            fn (SendBroadcastNotificationJob $job) => $job->type === 'low_seats'
                && $job->data['schedule_id'] === $low->id,
        );
        $this->assertSame(
            1,
            BroadcastDispatch::where('event_type', 'low_seats')->count(),
        );

        // Running the sweep again must not double-blast.
        app(BroadcastNotificationService::class)->sweepLowSeats();
        $this->assertSame(
            1,
            BroadcastDispatch::where('event_type', 'low_seats')->count(),
        );
    }

    public function test_quiet_hours_defer_sends_to_next_morning(): void
    {
        $service = app(BroadcastNotificationService::class);
        $tz = BroadcastNotificationService::TIMEZONE;

        // Daytime → send immediately.
        $this->assertNull(
            $service->quietHoursDelay(CarbonImmutable::parse('2026-06-09 12:00', $tz)),
        );

        // Late evening → defer to 08:00 the next day.
        $evening = $service->quietHoursDelay(CarbonImmutable::parse('2026-06-09 22:30', $tz));
        $this->assertNotNull($evening);
        $this->assertSame('2026-06-10 08:00', $evening->format('Y-m-d H:i'));

        // After midnight → defer to 08:00 the same day.
        $earlyMorning = $service->quietHoursDelay(CarbonImmutable::parse('2026-06-09 03:00', $tz));
        $this->assertSame('2026-06-09 08:00', $earlyMorning->format('Y-m-d H:i'));
    }
}
