<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PassengerBirthDateTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Birth Date Trip',
            'slug' => 'birth-date-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_create_booking_persists_passenger_birth_date(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Mr.',
                'name' => 'Birthday Person',
                'phone' => '0810000000',
                'birth_date' => '2000-01-15',
            ]],
        );

        $passenger = $booking->passengers()->first();
        $this->assertSame('2000-01-15', $passenger->birth_date->format('Y-m-d'));
        $this->assertSame(Carbon::parse('2000-01-15')->age, $passenger->age);
    }

    public function test_booking_endpoint_allows_missing_birth_date(): void
    {
        // Temporary: birth_date is optional until the production mobile app
        // ships the field. A booking without it must still succeed.
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [[
                    'title' => 'นาย',
                    'name' => 'No Birthday',
                    'nickname' => 'NB',
                    'id_card' => '1234567890123',
                    'phone' => '0810000000',
                    'blood_group' => 'A',
                    'halal_food' => false,
                    'emergency_contact' => 'Mom',
                    'emergency_phone' => '0820000000',
                    // birth_date intentionally omitted
                ]],
            ])
            ->assertCreated();
    }

    public function test_manifest_exposes_birth_date_and_age(): void
    {
        $admin = $this->makeAdmin();
        $customer = User::factory()->create();
        $schedule = $this->makeSchedule();

        app(BookingService::class)->createBooking(
            userId: $customer->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Ms.',
                'name' => 'Manifest Person',
                'phone' => '0810000000',
                'birth_date' => '1995-06-15',
            ]],
        );

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk();

        $manifest = $response->json('data.0.passenger_manifest.0');
        $this->assertSame('1995-06-15', $manifest['birth_date']);
        $this->assertSame(Carbon::parse('1995-06-15')->age, $manifest['age']);
    }

    public function test_admin_can_backfill_passenger_birth_date(): void
    {
        $admin = $this->makeAdmin();
        $customer = User::factory()->create();
        $schedule = $this->makeSchedule();

        // Booking created without a birth date (as legacy bookings would be).
        $booking = app(BookingService::class)->createBooking(
            userId: $customer->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Mr.',
                'name' => 'Legacy Person',
                'phone' => '0810000000',
            ]],
        );
        $passenger = $booking->passengers()->first();
        $this->assertNull($passenger->birth_date);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/passengers/{$passenger->id}", [
                'birth_date' => '1990-03-20',
            ])
            ->assertOk()
            ->assertJsonPath('data.birth_date', '1990-03-20')
            ->assertJsonPath('data.age', Carbon::parse('1990-03-20')->age);

        $this->assertSame('1990-03-20', $passenger->fresh()->birth_date->format('Y-m-d'));
    }

    public function test_non_admin_cannot_update_passenger(): void
    {
        $customer = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = app(BookingService::class)->createBooking(
            userId: $customer->id,
            scheduleId: $schedule->id,
            passengers: [['title' => 'Mr.', 'name' => 'P', 'phone' => '0810000000']],
        );
        $passenger = $booking->passengers()->first();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/admin/passengers/{$passenger->id}", [
                'birth_date' => '1990-03-20',
            ])
            ->assertForbidden();
    }

    public function test_profile_update_accepts_birth_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', ['birth_date' => '1988-12-31'])
            ->assertOk()
            ->assertJsonPath('data.birth_date', '1988-12-31')
            ->assertJsonPath('data.age', Carbon::parse('1988-12-31')->age);
    }
}
