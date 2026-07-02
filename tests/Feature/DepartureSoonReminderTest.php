<?php

namespace Tests\Feature;

use App\Jobs\SendDepartureSoonRemindersJob;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ~2–3h pre-departure push, keyed on the real departure time (departs_at, Thai
 * wall-clock). Time is frozen to 20:00 Bangkok so the windows are deterministic.
 */
class DepartureSoonReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-07-05 13:00 UTC = 20:00 Asia/Bangkok.
        Carbon::setTestNow(Carbon::parse('2026-07-05 13:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(?string $departsAt, string $departureDate = '2026-07-05'): Booking
    {
        $trip = Trip::create([
            'title' => 'Dawn Trek', 'slug' => 'dawn-'.uniqid(), 'type' => 'trekking',
            'location' => 'X', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1800, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => $departureDate,
            'total_seats' => 10, 'booked_seats' => 1, 'transport_type' => 'van', 'status' => 'open',
            'departs_at' => $departsAt,
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1800,
        ]);
    }

    private function reminders(Booking $b)
    {
        return SmartNotification::where('type', 'trip_departure_soon')
            ->where('data->booking_ref', $b->booking_ref);
    }

    public function test_reminds_when_departure_is_within_the_window(): void
    {
        $b = $this->booking('2026-07-05 22:00:00'); // 2h ahead of 20:00
        (new SendDepartureSoonRemindersJob)->handle();
        $this->assertSame(1, $this->reminders($b)->count());
    }

    public function test_no_reminder_when_departure_is_far_out(): void
    {
        $b = $this->booking('2026-07-06 06:00:00'); // 10h ahead
        (new SendDepartureSoonRemindersJob)->handle();
        $this->assertSame(0, $this->reminders($b)->count());
    }

    public function test_no_reminder_after_departure_has_passed(): void
    {
        $b = $this->booking('2026-07-05 19:00:00'); // 1h ago
        (new SendDepartureSoonRemindersJob)->handle();
        $this->assertSame(0, $this->reminders($b)->count());
    }

    public function test_night_before_departure_is_covered_and_labelled(): void
    {
        // Van leaves tonight 22:00 for a trip dated tomorrow.
        $b = $this->booking('2026-07-05 22:00:00', departureDate: '2026-07-06');
        (new SendDepartureSoonRemindersJob)->handle();

        $note = $this->reminders($b)->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('รถออกก่อนวันทริป', $note->body);
    }

    public function test_reminder_is_sent_only_once(): void
    {
        $b = $this->booking('2026-07-05 22:00:00');
        (new SendDepartureSoonRemindersJob)->handle();
        (new SendDepartureSoonRemindersJob)->handle();
        $this->assertSame(1, $this->reminders($b)->count());
    }

    public function test_day_only_round_without_departs_at_is_skipped(): void
    {
        $b = $this->booking(null);
        (new SendDepartureSoonRemindersJob)->handle();
        $this->assertSame(0, $this->reminders($b)->count());
    }
}
