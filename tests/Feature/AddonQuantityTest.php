<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AddonQuantityTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $addonItems = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Addon Trip',
            'slug' => 'addon-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1000,
            'status' => 'active',
            'must_know' => ['items' => $addonItems],
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

    /** @return array<int, array<string, mixed>> */
    private function passengers(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn ($i) => [
                'title' => 'นาย',
                'name' => "ผู้เดินทาง {$i}",
                'nickname' => "คน{$i}",
                'id_card' => '123456789012'.$i,
                'phone' => '0812345678',
                'blood_group' => 'O',
                'halal_food' => false,
                'emergency_contact' => 'แม่',
                'emergency_phone' => '0898765432',
            ])
            ->all();
    }

    public function test_per_person_addon_charges_only_the_chosen_quantity(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'เช่าเสื่อ', 'price' => 150, 'price_type' => 'per_person'],
        ]);
        $user = User::factory()->create();

        // ไป 4 คน แต่เอาเสื่อแค่ 2 ผืน
        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(4),
            selectedAddons: [['index' => 0, 'quantity' => 2]],
        );

        $this->assertEquals(300, (float) $booking->addons_total);
        $this->assertEquals(4300, (float) $booking->total_amount);
        $this->assertEquals(2, $booking->selected_addons[0]['quantity']);
        $this->assertEquals(300, $booking->selected_addons[0]['total_price']);
    }

    public function test_per_booking_addon_can_be_taken_more_than_once(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'เต็นท์', 'price' => 500, 'price_type' => 'per_booking'],
        ]);
        $user = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
            selectedAddons: [['index' => 0, 'quantity' => 3]],
        );

        $this->assertEquals(1500, (float) $booking->addons_total);
        $this->assertEquals(3, $booking->selected_addons[0]['quantity']);
    }

    public function test_legacy_index_only_payload_keeps_the_old_quantities(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'เช่าเสื่อ', 'price' => 150, 'price_type' => 'per_person'],
            ['name' => 'เต็นท์', 'price' => 500, 'price_type' => 'per_booking'],
        ]);
        $user = User::factory()->create();

        // เว็บ/LIFF ยังส่ง index เปล่า ๆ — ต่อคนคิดเท่าจำนวนผู้เดินทาง, ต่อชิ้นคิด 1
        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(3),
            selectedAddons: [0, 1],
        );

        $this->assertEquals(3, $booking->selected_addons[0]['quantity']);
        $this->assertEquals(450, $booking->selected_addons[0]['total_price']);
        $this->assertEquals(1, $booking->selected_addons[1]['quantity']);
        $this->assertEquals(950, (float) $booking->addons_total);
    }

    public function test_per_person_quantity_cannot_exceed_traveler_count(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'เช่าเสื่อ', 'price' => 150, 'price_type' => 'per_person'],
        ]);
        $user = User::factory()->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('จำนวนรายการเสริมต่อคนมากกว่าจำนวนผู้เดินทาง');

        app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
            selectedAddons: [['index' => 0, 'quantity' => 3]],
        );
    }

    public function test_zero_quantity_addons_are_ignored(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'เช่าเสื่อ', 'price' => 150, 'price_type' => 'per_person'],
        ]);
        $user = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
            selectedAddons: [['index' => 0, 'quantity' => 0]],
        );

        $this->assertEquals(0, (float) $booking->addons_total);
        $this->assertSame([], $booking->selected_addons);
    }

    public function test_invalid_addon_index_is_rejected(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'เช่าเสื่อ', 'price' => 150, 'price_type' => 'per_person'],
        ]);
        $user = User::factory()->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('รายการเสริมที่เลือกไม่ถูกต้อง');

        app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(1),
            selectedAddons: [['index' => 7, 'quantity' => 1]],
        );
    }

    public function test_endpoint_accepts_both_the_new_and_the_legacy_shape(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule([
            ['name' => 'เช่าเสื่อ', 'price' => 150, 'price_type' => 'per_person'],
        ]);
        $user = User::factory()->create();

        // แอป: {index, quantity}
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(3),
                'selected_addons' => [['index' => 0, 'quantity' => 2]],
            ])
            ->assertCreated();

        $this->assertEquals(2, Booking::latest('id')->first()->selected_addons[0]['quantity']);

        // เว็บ/LIFF: index เปล่า ๆ
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(3),
                'selected_addons' => [0],
            ])
            ->assertCreated();

        $this->assertEquals(3, Booking::latest('id')->first()->selected_addons[0]['quantity']);
    }

    public function test_endpoint_rejects_a_malformed_addon_entry(): void
    {
        $schedule = $this->makeSchedule([
            ['name' => 'เช่าเสื่อ', 'price' => 150, 'price_type' => 'per_person'],
        ]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'selected_addons' => [['quantity' => 2]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('selected_addons.0.index');
    }
}
