<?php

namespace Tests\Feature;

use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\TripAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripAlertTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(float $price = 2000): Trip
    {
        return Trip::create([
            'title' => 'Doi Inthanon Sunrise',
            'slug' => 'doi-inthanon',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => $price,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, array $attrs = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $attrs));
    }

    public function test_user_can_subscribe_and_unsubscribe_from_a_trip(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/alerts")
            ->assertCreated()
            ->assertJsonPath('data.trip_slug', $trip->slug)
            ->assertJsonPath('data.alert_price_drop', true);

        $this->assertDatabaseHas('trip_alerts', [
            'user_id' => $user->id,
            'trip_id' => $trip->id,
        ]);

        // Idempotent — re-subscribing updates the same row.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/alerts", ['alert_low_seats' => false])
            ->assertCreated()
            ->assertJsonPath('data.alert_low_seats', false);

        $this->assertSame(1, TripAlert::where('user_id', $user->id)->count());

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/trips/{$trip->slug}/alerts")
            ->assertOk();

        $this->assertDatabaseMissing('trip_alerts', [
            'user_id' => $user->id,
            'trip_id' => $trip->id,
        ]);
    }

    public function test_subscriber_is_notified_when_a_new_schedule_opens(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();
        TripAlert::create(['user_id' => $user->id, 'trip_id' => $trip->id]);

        // Creating an open schedule fires the observer.
        $this->makeSchedule($trip);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $user->id,
            'type' => 'trip_alert',
        ]);

        $note = SmartNotification::where('user_id', $user->id)->first();
        $this->assertSame('new_schedule', $note->data['alert_type']);
        $this->assertSame($trip->slug, $note->data['trip_slug']);
    }

    public function test_closed_schedule_does_not_notify_until_it_opens(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();
        TripAlert::create(['user_id' => $user->id, 'trip_id' => $trip->id]);

        $schedule = $this->makeSchedule($trip, ['status' => 'closed']);
        $this->assertSame(0, SmartNotification::where('user_id', $user->id)->count());

        $schedule->update(['status' => 'open']);
        $this->assertSame(1, SmartNotification::where('user_id', $user->id)->count());
    }

    public function test_price_drop_notifies_only_on_decrease(): void
    {
        $trip = $this->makeTrip(2000);
        $this->makeSchedule($trip);
        $user = User::factory()->create();
        TripAlert::create(['user_id' => $user->id, 'trip_id' => $trip->id]);

        $service = app(TripAlertService::class);

        // First sweep just sets the baseline.
        $service->processAll();
        $this->assertSame(0, SmartNotification::where('type', 'trip_alert')->count());

        // Price drops -> one notification.
        $trip->update(['price_per_person' => 1500]);
        $service->processAll();
        $this->assertSame(1, SmartNotification::where('type', 'trip_alert')->count());

        // No change -> no duplicate.
        $service->processAll();
        $this->assertSame(1, SmartNotification::where('type', 'trip_alert')->count());
    }

    public function test_low_seats_notifies_once_per_schedule(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, ['total_seats' => 10, 'booked_seats' => 7]);
        $user = User::factory()->create();
        TripAlert::create([
            'user_id' => $user->id,
            'trip_id' => $trip->id,
            'low_seat_threshold' => 5,
        ]);

        $service = app(TripAlertService::class);
        $service->processAll();

        $lowSeatNotes = SmartNotification::where('type', 'trip_alert')
            ->get()
            ->filter(fn ($n) => ($n->data['alert_type'] ?? null) === 'low_seats');
        $this->assertCount(1, $lowSeatNotes);

        // Re-running does not duplicate.
        $service->processAll();
        $lowSeatNotes = SmartNotification::where('type', 'trip_alert')
            ->get()
            ->filter(fn ($n) => ($n->data['alert_type'] ?? null) === 'low_seats');
        $this->assertCount(1, $lowSeatNotes);
    }

    private function lowSeatNoteCount(): int
    {
        return SmartNotification::where('type', 'trip_alert')
            ->get()
            ->filter(fn ($n) => ($n->data['alert_type'] ?? null) === 'low_seats')
            ->count();
    }

    public function test_low_seats_re_alerts_at_each_seat_level(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, ['total_seats' => 10, 'booked_seats' => 7]); // 3 left
        $user = User::factory()->create();
        TripAlert::create([
            'user_id' => $user->id,
            'trip_id' => $trip->id,
            'low_seat_threshold' => 3,
        ]);

        $service = app(TripAlertService::class);

        // 3 → 2 → 1 left, each step re-alerts once.
        $service->processAll();
        $this->assertSame(1, $this->lowSeatNoteCount());

        $schedule->update(['booked_seats' => 8]);
        $service->processAll();
        $this->assertSame(2, $this->lowSeatNoteCount());

        $schedule->update(['booked_seats' => 9]);
        $service->processAll();
        $this->assertSame(3, $this->lowSeatNoteCount());

        // Re-running at the same level does not duplicate.
        $service->processAll();
        $this->assertSame(3, $this->lowSeatNoteCount());
    }

    public function test_real_time_low_seat_fan_out_to_trip_subscribers(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, ['total_seats' => 10, 'booked_seats' => 8]); // 2 left

        $watcher = User::factory()->create();
        TripAlert::create([
            'user_id' => $watcher->id,
            'trip_id' => $trip->id,
            'low_seat_threshold' => 3,
        ]);
        // A subscriber whose threshold is below the current count is not alerted.
        $picky = User::factory()->create();
        TripAlert::create([
            'user_id' => $picky->id,
            'trip_id' => $trip->id,
            'low_seat_threshold' => 1,
        ]);

        app(TripAlertService::class)->notifyLowSeats($schedule->fresh()->load('trip'));

        $this->assertSame(1, $this->lowSeatNoteCount());
        $this->assertSame(1, SmartNotification::where('user_id', $watcher->id)->count());
        $this->assertSame(0, SmartNotification::where('user_id', $picky->id)->count());
    }
}
