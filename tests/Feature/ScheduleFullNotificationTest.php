<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleFullNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config()->set('services.thaibulksms.enabled', false);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
    }

    private function makeSchedule(int $totalSeats): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Full Trip',
            'slug' => 'full-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => $totalSeats,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => $totalSeats,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(TripSchedule $schedule, int $count): void
    {
        $passengers = [];
        for ($i = 0; $i < $count; $i++) {
            $passengers[] = [
                'title' => 'Mr.',
                'name' => "Passenger {$i}",
                'phone' => '081-234-5678',
                'email' => "p{$i}@example.test",
            ];
        }

        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $passengers,
        );
    }

    public function test_staff_notified_when_booking_fills_the_schedule(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $operator = User::factory()->create();
        $operator->assignRole('operator');

        $schedule = $this->makeSchedule(2);
        $this->book($schedule, 2); // exactly fills the last seats

        foreach ([$admin, $operator] as $staff) {
            $this->assertDatabaseHas('smart_notifications', [
                'user_id' => $staff->id,
                'type' => 'schedule_full',
            ]);
        }
    }

    public function test_no_notification_while_seats_remain(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule(5);
        $this->book($schedule, 2); // 3 seats still open

        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $admin->id,
            'type' => 'schedule_full',
        ]);
    }

    public function test_customer_is_not_sent_the_full_notification(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule(1);
        $customer = User::factory()->create();

        app(BookingService::class)->createBooking(
            userId: $customer->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Mr.',
                'name' => 'Solo Booker',
                'phone' => '081-234-5678',
                'email' => 'solo@example.test',
            ]],
        );

        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $customer->id,
            'type' => 'schedule_full',
        ]);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $admin->id,
            'type' => 'schedule_full',
        ]);
    }
}
