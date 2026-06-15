<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingBirthdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeUpcomingSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Group Trip',
            'slug' => 'group-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(14)->toDateString(),
            'return_date' => now()->addDays(14)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 3,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function groupBooking(User $booker, TripSchedule $schedule): Booking
    {
        return Booking::create([
            'booking_ref' => 'LLK-GRP-'.random_int(1000, 9999),
            'user_id' => $booker->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'payment_type' => 'full',
        ]);
    }

    public function test_show_lists_all_passengers_of_the_booking(): void
    {
        $booker = User::factory()->create(['name' => 'หัวหน้ากลุ่ม']);
        $schedule = $this->makeUpcomingSchedule();
        $booking = $this->groupBooking($booker, $schedule);

        foreach (['สมาชิก หนึ่ง', 'สมาชิก สอง', 'สมาชิก สาม'] as $name) {
            BookingPassenger::create(['booking_id' => $booking->id, 'name' => $name]);
        }

        $token = $booking->ensureBirthdateToken();

        $response = $this->get("/booking-birthdate/{$token}")->assertOk();
        foreach (['สมาชิก หนึ่ง', 'สมาชิก สอง', 'สมาชิก สาม'] as $name) {
            $response->assertSee($name);
        }
    }

    public function test_submit_fills_birth_dates_for_the_whole_booking(): void
    {
        $booker = User::factory()->create(['name' => 'หัวหน้ากลุ่ม', 'id_card' => '1111111111111']);
        $schedule = $this->makeUpcomingSchedule();
        $booking = $this->groupBooking($booker, $schedule);

        $leader = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'หัวหน้ากลุ่ม',
            'id_card' => '1111111111111',
        ]);
        $friendA = BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'เพื่อน เอ']);
        $friendB = BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'เพื่อน บี']);

        $token = $booking->ensureBirthdateToken();

        $this->post("/booking-birthdate/{$token}", [
            'birth_dates' => [
                $leader->id => '1985-01-01',
                $friendA->id => '1990-06-15',
                $friendB->id => '1995-12-31',
            ],
        ])
            ->assertRedirect(route('public.birthdate.booking.show', $token))
            ->assertSessionHas('saved', true);

        $this->assertSame('1985-01-01', $leader->fresh()->birth_date->format('Y-m-d'));
        $this->assertSame('1990-06-15', $friendA->fresh()->birth_date->format('Y-m-d'));
        $this->assertSame('1995-12-31', $friendB->fresh()->birth_date->format('Y-m-d'));

        // Booker's own profile is synced from their matching row.
        $this->assertSame('1985-01-01', $booker->fresh()->birth_date->format('Y-m-d'));
    }

    public function test_submit_skips_blank_dates_and_rejects_future_dates(): void
    {
        $booker = User::factory()->create();
        $schedule = $this->makeUpcomingSchedule();
        $booking = $this->groupBooking($booker, $schedule);
        $p1 = BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'A']);
        $p2 = BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'B']);

        $token = $booking->ensureBirthdateToken();

        // Future date is rejected; nothing saved.
        $this->post("/booking-birthdate/{$token}", [
            'birth_dates' => [$p1->id => now()->addYear()->toDateString()],
        ])->assertSessionHasErrors('birth_dates.'.$p1->id);
        $this->assertNull($p1->fresh()->birth_date);

        // Blank entries are simply skipped — partial save is allowed.
        $this->post("/booking-birthdate/{$token}", [
            'birth_dates' => [$p1->id => '1992-02-02', $p2->id => ''],
        ])->assertSessionHas('saved');
        $this->assertSame('1992-02-02', $p1->fresh()->birth_date->format('Y-m-d'));
        $this->assertNull($p2->fresh()->birth_date);
    }

    public function test_cannot_write_passengers_outside_the_booking(): void
    {
        $booker = User::factory()->create();
        $schedule = $this->makeUpcomingSchedule();
        $booking = $this->groupBooking($booker, $schedule);
        $mine = BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'Mine']);

        // A passenger that belongs to a different booking.
        $otherBooking = $this->groupBooking(User::factory()->create(), $schedule);
        $foreign = BookingPassenger::create(['booking_id' => $otherBooking->id, 'name' => 'Foreign']);

        $token = $booking->ensureBirthdateToken();

        $this->post("/booking-birthdate/{$token}", [
            'birth_dates' => [
                $mine->id => '1991-01-01',
                $foreign->id => '1991-01-01',
            ],
        ])->assertSessionHas('saved');

        $this->assertSame('1991-01-01', $mine->fresh()->birth_date->format('Y-m-d'));
        $this->assertNull($foreign->fresh()->birth_date, 'Must not write passengers from another booking.');
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->get('/booking-birthdate/nope12345')->assertNotFound();
    }

    public function test_command_generates_links_for_upcoming_bookings_with_missing_dates(): void
    {
        $booker = User::factory()->create();
        $schedule = $this->makeUpcomingSchedule();
        $booking = $this->groupBooking($booker, $schedule);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'No DOB']);

        // A fully-filled booking should be excluded.
        $filled = $this->groupBooking($booker, $schedule);
        BookingPassenger::create([
            'booking_id' => $filled->id,
            'name' => 'Has DOB',
            'birth_date' => '1990-01-01',
        ]);

        $this->artisan('birthdate:booking-links')
            ->expectsOutputToContain('สร้างลิงก์ให้การจอง 1 รายการ')
            ->assertExitCode(0);

        $this->assertNotNull($booking->fresh()->birthdate_token);
        $this->assertNull($filled->fresh()->birthdate_token);
    }
}
