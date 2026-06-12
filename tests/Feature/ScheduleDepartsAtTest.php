<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleDepartsAtTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Night Departure Trip',
            'slug' => 'night-departure-trip',
            'type' => 'trekking',
            'location' => 'Phu Kradueng',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function makeAdmin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_effective_departs_at_falls_back_to_departure_date(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->assertSame(
            $schedule->departure_date->startOfDay()->toDateTimeString(),
            $schedule->effectiveDepartsAt()->toDateTimeString(),
        );
    }

    public function test_effective_departs_at_uses_departs_at_when_set(): void
    {
        $tripDate = now()->addDays(10);
        $departsAt = $tripDate->copy()->subDay()->setTime(23, 30);

        $schedule = $this->makeSchedule($this->makeTrip(), [
            'departure_date' => $tripDate->toDateString(),
            'departs_at' => $departsAt->toDateTimeString(),
        ]);

        $this->assertSame($departsAt->toDateTimeString(), $schedule->effectiveDepartsAt()->toDateTimeString());
        $this->assertSame($departsAt->toDateString(), $schedule->effectiveDepartureDate()->toDateString());
    }

    public function test_departure_labels_include_time_only_when_departs_at_set(): void
    {
        $trip = $this->makeTrip();

        $plain = $this->makeSchedule($trip, ['departure_date' => '2026-06-13', 'return_date' => '2026-06-14']);
        $this->assertSame('13/06/2026', $plain->departureLabelShort());

        $night = $this->makeSchedule($trip, [
            'departure_date' => '2026-06-13',
            'return_date' => '2026-06-14',
            'departs_at' => '2026-06-12 23:30:00',
        ]);
        $this->assertSame('12/06/2026 23:30 น.', $night->departureLabelShort());
        $this->assertStringContainsString('23:30 น.', $night->departureLabelThai());
        $this->assertStringContainsString('12', $night->departureLabelThai());
    }

    public function test_departing_on_scope_uses_real_departure_date(): void
    {
        $trip = $this->makeTrip();

        $night = $this->makeSchedule($trip, [
            'departure_date' => '2026-06-13',
            'return_date' => '2026-06-14',
            'departs_at' => '2026-06-12 23:30:00',
        ]);
        $plain = $this->makeSchedule($trip, [
            'departure_date' => '2026-06-13',
            'return_date' => '2026-06-14',
        ]);

        $onThe12th = TripSchedule::departingOn('2026-06-12')->pluck('id');
        $this->assertTrue($onThe12th->contains($night->id));
        $this->assertFalse($onThe12th->contains($plain->id));

        $onThe13th = TripSchedule::departingOn('2026-06-13')->pluck('id');
        $this->assertFalse($onThe13th->contains($night->id));
        $this->assertTrue($onThe13th->contains($plain->id));
    }

    public function test_admin_can_create_schedule_with_night_before_departs_at(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $tripDate = now()->addDays(10)->toDateString();
        $departsAt = now()->addDays(9)->format('Y-m-d').' 23:30:00';

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/schedules', [
            'trip_id' => $trip->id,
            'departure_date' => $tripDate,
            'departs_at' => $departsAt,
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 10,
            'transport_type' => 'van',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.departure_date', $tripDate)
            ->assertJsonPath('data.departs_at', $departsAt);
    }

    public function test_departs_at_outside_allowed_window_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/schedules', [
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            // ออกรถก่อนวันทริปเกิน 1 วัน — ไม่อนุญาต
            'departs_at' => now()->addDays(7)->format('Y-m-d').' 23:30:00',
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 10,
            'transport_type' => 'van',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['departs_at']);
    }

    public function test_admin_can_update_departs_at(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $departsAt = $schedule->departure_date->copy()->subDay()->setTime(23, 30);

        $response = $this->actingAs($admin)->putJson("/api/v1/admin/schedules/{$schedule->id}", [
            'departs_at' => $departsAt->toDateTimeString(),
        ]);

        $response->assertOk()->assertJsonPath('data.departs_at', $departsAt->toDateTimeString());
    }

    public function test_public_schedule_endpoint_exposes_departs_at(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), [
            'departure_date' => now()->addDays(10)->toDateString(),
            'departs_at' => now()->addDays(9)->format('Y-m-d').' 23:30:00',
        ]);

        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.departs_at', now()->addDays(9)->format('Y-m-d').' 23:30:00');
    }

    public function test_booking_deadlines_use_real_departure_date(): void
    {
        $trip = $this->makeTrip();
        $tripDate = now()->addDays(10);

        $schedule = $this->makeSchedule($trip, [
            'departure_date' => $tripDate->toDateString(),
            'departs_at' => $tripDate->copy()->subDay()->setTime(23, 30)->toDateTimeString(),
        ]);

        $user = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => 'LLK-20260613-0001',
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);

        // เส้นตายแก้ไข = 1 วันก่อนวันออกรถจริง (ไม่ใช่วันทริป)
        $this->assertSame(
            $tripDate->copy()->subDays(2)->endOfDay()->toDateTimeString(),
            $booking->modificationDeadline()->toDateTimeString(),
        );
    }
}
