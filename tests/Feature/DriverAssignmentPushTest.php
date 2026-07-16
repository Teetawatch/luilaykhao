<?php

namespace Tests\Feature;

use App\Jobs\SendDriverAssignmentPushJob;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DriverAssignmentPushTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Assign Trip',
            'slug' => 'assign-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
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

    private function vehicle(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'capacity' => 12,
            'license_plate' => 'กข 1234',
        ], $attrs));
    }

    public function test_assigning_a_vehicle_pushes_to_its_linked_driver(): void
    {
        $driver = User::factory()->create();
        $vehicle = $this->vehicle(['driver_user_id' => $driver->id]);
        $schedule = $this->schedule();

        $schedule->update(['vehicle_id' => $vehicle->id]);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $driver->id,
            'type' => 'driver_assignment',
        ]);
    }

    public function test_driver_is_matched_by_phone_when_not_linked_by_id(): void
    {
        $driver = User::factory()->create(['phone' => '0812345678']);
        // Vehicle stores the phone in a different format — digits should match.
        $vehicle = $this->vehicle(['driver_phone' => '081-234-5678']);
        $schedule = $this->schedule();

        $schedule->update(['vehicle_id' => $vehicle->id]);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $driver->id,
            'type' => 'driver_assignment',
        ]);
    }

    public function test_no_push_when_vehicle_has_no_resolvable_driver(): void
    {
        $vehicle = $this->vehicle(); // no driver_user_id, no driver_phone
        $schedule = $this->schedule();

        $schedule->update(['vehicle_id' => $vehicle->id]);

        $this->assertDatabaseMissing('smart_notifications', [
            'type' => 'driver_assignment',
        ]);
    }

    public function test_no_duplicate_push_when_vehicle_unchanged(): void
    {
        Queue::fake();
        $driver = User::factory()->create();
        $vehicle = $this->vehicle(['driver_user_id' => $driver->id]);
        $schedule = $this->schedule();

        $schedule->update(['vehicle_id' => $vehicle->id]);
        Queue::assertPushed(SendDriverAssignmentPushJob::class, 1);

        // A later edit that doesn't touch the vehicle must not re-notify.
        $schedule->update(['total_seats' => 15]);
        Queue::assertPushed(SendDriverAssignmentPushJob::class, 1);
    }
}
