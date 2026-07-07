<?php

namespace Tests\Feature;

use App\Jobs\SendUnderfilledTripWarningsJob;
use App\Mail\TripUnderfilledWarningMail;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 5 days before departure, warn customers whose round hasn't reached the 8-seat
 * minimum that the trip may be cancelled. Time is frozen so the target date is
 * deterministic.
 */
class UnderfilledTripWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-07-05 03:00 UTC = 10:00 Asia/Bangkok → target date = 2026-07-10.
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:00:00', 'UTC'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(int $bookedSeats, string $status = 'open', string $departureDate = '2026-07-10'): Booking
    {
        $trip = Trip::create([
            'title' => 'Dawn Trek', 'slug' => 'dawn-'.uniqid(), 'type' => 'trekking',
            'location' => 'X', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 12, 'price_per_person' => 1800, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => $departureDate,
            'total_seats' => 12, 'booked_seats' => $bookedSeats, 'transport_type' => 'van', 'status' => $status,
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['email' => 'cust-'.uniqid().'@example.com'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1800,
        ]);
    }

    private function warnings(Booking $b)
    {
        return SmartNotification::where('type', 'trip_underfilled_warning')
            ->where('data->booking_ref', $b->booking_ref);
    }

    public function test_warns_when_round_is_underfilled_five_days_out(): void
    {
        $b = $this->booking(bookedSeats: 3);

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertSent(TripUnderfilledWarningMail::class, 1);
        $this->assertSame(1, $this->warnings($b)->count());
    }

    public function test_no_warning_when_minimum_is_met(): void
    {
        $this->booking(bookedSeats: 8);

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertNothingSent();
    }

    public function test_no_warning_for_cancelled_round(): void
    {
        $this->booking(bookedSeats: 3, status: 'cancelled');

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertNothingSent();
    }

    public function test_no_warning_when_departure_is_not_five_days_out(): void
    {
        $this->booking(bookedSeats: 3, departureDate: '2026-07-12'); // 7 days out

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertNothingSent();
    }

    public function test_warning_is_sent_only_once_per_daily_run(): void
    {
        $b = $this->booking(bookedSeats: 3);

        (new SendUnderfilledTripWarningsJob)->handle(app(MailService::class));

        Mail::assertSent(TripUnderfilledWarningMail::class, 1);
        $this->assertSame(1, $this->warnings($b)->count());
    }

    public function test_email_renders_with_the_seat_details(): void
    {
        $b = $this->booking(bookedSeats: 3);

        $html = (new TripUnderfilledWarningMail($b, 5, 3, 8))->render();

        $this->assertStringContainsString('ทริปอาจถูกยกเลิก', $html);
        $this->assertStringContainsString('Dawn Trek', $html);
        $this->assertStringContainsString('5 ท่าน', $html); // 8 minimum − 3 booked
    }
}
