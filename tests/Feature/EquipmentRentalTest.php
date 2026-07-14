<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EquipmentRentalTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $rentalItems = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
            'rental_items' => $rentalItems,
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function passenger(): array
    {
        return [[
            'title' => 'Mr.',
            'name' => 'Passenger One',
            'phone' => '0812345678',
            'email' => 'p1@example.test',
        ]];
    }

    public function test_rental_quantity_adds_to_total_and_is_snapshotted(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'ถุงนอน', 'price' => 200, 'image_url' => ''],
            ['name' => 'ไม้เท้าเดินป่า', 'price' => 100],
        ]);
        $user = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
            selectedRentals: [
                ['index' => 0, 'quantity' => 2], // 2 × 200 = 400
                ['index' => 1, 'quantity' => 1], // 1 × 100 = 100
            ],
        );

        // 1500 (seat) + 500 (rentals)
        $this->assertEquals(500, (float) $booking->rentals_total);
        $this->assertEquals(2000, (float) $booking->total_amount);

        $this->assertCount(2, $booking->selected_rentals);
        $this->assertEquals('ถุงนอน', $booking->selected_rentals[0]['name']);
        $this->assertEquals(2, $booking->selected_rentals[0]['quantity']);
        $this->assertEquals(400, $booking->selected_rentals[0]['total_price']);
    }

    public function test_zero_quantity_rentals_are_ignored(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'ถุงนอน', 'price' => 200],
        ]);
        $user = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
            selectedRentals: [['index' => 0, 'quantity' => 0]],
        );

        $this->assertEquals(0, (float) $booking->rentals_total);
        $this->assertEquals(1500, (float) $booking->total_amount);
        $this->assertSame([], $booking->selected_rentals);
    }

    public function test_invalid_rental_index_is_rejected(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'ถุงนอน', 'price' => 200],
        ]);
        $user = User::factory()->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('อุปกรณ์เช่าที่เลือกไม่ถูกต้อง');

        app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
            selectedRentals: [['index' => 5, 'quantity' => 1]],
        );
    }

    public function test_admin_can_save_rental_items_and_public_resource_exposes_them(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Category::firstOrCreate(['slug' => 'trekking'], ['name' => 'เดินป่า']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $trip = Trip::create([
            'title' => 'Rental Trip',
            'slug' => 'rental-trip',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);

        $image = 'https://media.luilaykhao.com/media/sleeping-bag.jpg';

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", [
                'title' => $trip->title,
                'type' => 'trekking',
                'location' => $trip->location,
                'region' => $trip->region,
                'difficulty' => 'easy',
                'duration_days' => 1,
                'max_participants' => 8,
                'price_per_person' => 1000,
                'rental_items' => [
                    ['name' => 'ถุงนอน', 'price' => 200, 'image_url' => $image],
                ],
            ])
            ->assertOk();

        $this->assertSame('ถุงนอน', $trip->fresh()->rental_items[0]['name']);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.rental_items.0.name', 'ถุงนอน')
            ->assertJsonPath('data.rental_items.0.image_url', $image);
    }
}
