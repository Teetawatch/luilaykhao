<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards the queries that were rewritten to be DB-agnostic ahead of the
 * MySQL → PostgreSQL migration: analytics date grouping (previously MySQL-only
 * DATE_FORMAT / DAYOFWEEK) and case-insensitive search (whereLike → ILIKE on
 * Postgres). These run on SQLite here, and the same code must hold on MySQL
 * and Postgres.
 */
class DbAgnosticQueryTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title = 'Doi Inthanon Trek'): Trip
    {
        return Trip::create([
            'title' => $title, 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Chiang Mai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function bookOn(TripSchedule $schedule, Carbon $createdAt, float $paid = 3000): Booking
    {
        $booking = Booking::create([
            // Explicit unique ref: generateRef() derives its sequence from today's
            // bookings, which clashes once we backdate created_at below.
            'booking_ref' => 'LLK-'.strtoupper(uniqid()),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => $paid,
            'paid_amount' => $paid,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Pax', 'phone' => '0810000000',
        ]);
        // Set created_at directly so it lands on the day we want to assert on,
        // without Eloquent overwriting it with "now".
        Booking::where('id', $booking->id)->update(['created_at' => $createdAt]);

        return $booking;
    }

    public function test_analytics_groups_by_day_and_weekday_without_db_specific_functions(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']); // overview counts new customers
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);

        // Two confirmed bookings on the same day, one on another day.
        $dayA = Carbon::create(2026, 6, 17, 10, 0, 0);
        $dayB = Carbon::create(2026, 6, 19, 10, 0, 0);
        $this->bookOn($schedule, $dayA, 3000);
        $this->bookOn($schedule, $dayA->copy()->setTime(14, 0), 2000);
        $this->bookOn($schedule, $dayB, 1500);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/analytics/overview?from=2026-06-01&to=2026-06-30')
            ->assertOk();

        // Daily revenue trend, chronological, aggregated in PHP.
        $res->assertJsonPath('data.revenue_trend.0.period', '2026-06-17')
            ->assertJsonPath('data.revenue_trend.0.bookings', 2)
            ->assertJsonPath('data.revenue_trend.0.revenue', 5000)
            ->assertJsonPath('data.revenue_trend.1.period', '2026-06-19')
            ->assertJsonPath('data.revenue_trend.1.bookings', 1);

        // Day-of-week bucket (index 0 = Sunday … 6 = Saturday) must line up with
        // Carbon's numbering — the whole point of dropping MySQL DAYOFWEEK (1–7).
        $res->assertJsonPath('data.bookings_by_dow.'.$dayA->dayOfWeek.'.count', 2)
            ->assertJsonPath('data.bookings_by_dow.'.$dayB->dayOfWeek.'.count', 1);
    }

    public function test_trip_search_is_case_insensitive(): void
    {
        $this->makeTrip('Doi Inthanon Trek');
        $this->makeTrip('Phu Kradueng Adventure');

        // Lower-case query must still match the mixed-case title. On MySQL/SQLite
        // LIKE is already case-insensitive; on Postgres whereLike compiles to ILIKE.
        $this->getJson('/api/v1/trips?search=INTHANON')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Doi Inthanon Trek');
    }
}
