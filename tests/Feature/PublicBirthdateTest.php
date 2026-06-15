<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicBirthdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(array $attributes = []): User
    {
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $user = User::factory()->create($attributes);
        $user->assignRole('customer');

        return $user;
    }

    private function makeSchedule(string $departure): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'BD Trip',
            'slug' => 'bd-trip-'.uniqid(),
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
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function bookingFor(User $user, TripSchedule $schedule, string $ref): Booking
    {
        return Booking::create([
            'booking_ref' => $ref,
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'payment_type' => 'full',
        ]);
    }

    public function test_ensure_birthdate_token_is_idempotent(): void
    {
        $user = $this->makeCustomer();
        $first = $user->ensureBirthdateToken();
        $second = $user->fresh()->ensureBirthdateToken();

        $this->assertNotEmpty($first);
        $this->assertSame($first, $second);
    }

    public function test_show_page_renders_with_valid_token(): void
    {
        $user = $this->makeCustomer(['name' => 'คุณทดสอบ ระบบ']);
        $token = $user->ensureBirthdateToken();

        $this->get("/birthdate/{$token}")
            ->assertOk()
            ->assertSee('คุณทดสอบ ระบบ');
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->get('/birthdate/nonexistenttoken123')->assertNotFound();
    }

    public function test_submit_saves_birth_date_to_profile(): void
    {
        $user = $this->makeCustomer();
        $token = $user->ensureBirthdateToken();

        $this->post("/birthdate/{$token}", ['birth_date' => '1992-08-10'])
            ->assertRedirect(route('public.birthdate.show', $token))
            ->assertSessionHas('saved', true);

        $this->assertSame('1992-08-10', $user->fresh()->birth_date->format('Y-m-d'));
    }

    public function test_submit_rejects_future_date_without_saving(): void
    {
        $user = $this->makeCustomer();
        $token = $user->ensureBirthdateToken();

        $this->post("/birthdate/{$token}", ['birth_date' => now()->addYear()->toDateString()])
            ->assertSessionHasErrors('birth_date');

        $this->assertNull($user->fresh()->birth_date);
    }

    public function test_submit_propagates_to_own_upcoming_passenger_only(): void
    {
        $user = $this->makeCustomer(['name' => 'สมชาย ใจดี', 'id_card' => '1111111111111']);
        $token = $user->ensureBirthdateToken();

        $upcoming = $this->makeSchedule(now()->addDays(10)->toDateString());
        $booking = $this->bookingFor($user, $upcoming, 'LLK-UP-0001');

        // The customer's own row (matching id card) — should be filled.
        $self = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'สมชาย ใจดี',
            'id_card' => '1111111111111',
        ]);
        // A travelling friend on the same booking — must NOT be touched.
        $friend = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'เพื่อน คนหนึ่ง',
            'id_card' => '2222222222222',
        ]);

        // A past trip with a matching row — excluded because it already departed.
        $past = $this->makeSchedule(now()->subDays(5)->toDateString());
        $pastBooking = $this->bookingFor($user, $past, 'LLK-PAST-0001');
        $pastSelf = BookingPassenger::create([
            'booking_id' => $pastBooking->id,
            'name' => 'สมชาย ใจดี',
            'id_card' => '1111111111111',
        ]);

        $this->post("/birthdate/{$token}", ['birth_date' => '1990-01-01'])->assertSessionHas('saved');

        $this->assertSame('1990-01-01', $self->fresh()->birth_date->format('Y-m-d'));
        $this->assertNull($friend->fresh()->birth_date);
        $this->assertNull($pastSelf->fresh()->birth_date);
    }

    public function test_command_generates_links_only_for_customers_without_birth_date(): void
    {
        $missing = $this->makeCustomer(['birth_date' => null]);
        $has = $this->makeCustomer(['birth_date' => '1995-05-05']);

        $this->artisan('birthdate:links')
            ->expectsOutputToContain('สร้างลิงก์ให้ลูกค้า 1 คน')
            ->assertExitCode(0);

        $this->assertNotNull($missing->fresh()->birthdate_token);
        $this->assertNull($has->fresh()->birthdate_token);
    }
}
