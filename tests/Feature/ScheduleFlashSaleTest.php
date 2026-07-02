<?php

namespace Tests\Feature;

use App\Models\BroadcastDispatch;
use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-round flash sale: admin-set discounted price + end time, applied through
 * TripSchedule::effective_price (so bookings charge it), surfaced in resources,
 * and announced via a one-shot broadcast push.
 */
class ScheduleFlashSaleTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title = 'Doi Flash Trek'): Trip
    {
        return Trip::create([
            'title' => $title, 'slug' => str()->slug($title).'-'.uniqid(), 'type' => 'trekking',
            'location' => 'Chiang Mai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, array $attrs = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ], $attrs));
    }

    public function test_effective_price_uses_flash_price_while_active(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, [
            'flash_sale_enabled' => true,
            'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->addDay(),
        ]);

        $this->assertTrue($schedule->flashSaleActive());
        $this->assertSame(1990.0, $schedule->effective_price);   // charged at booking time
        $this->assertSame(3000.0, $schedule->original_price);    // struck-through price
    }

    public function test_flash_sale_falls_back_when_lapsed_or_sold_out(): void
    {
        $trip = $this->makeTrip();

        // Ended flash sale → normal price.
        $lapsed = $this->makeSchedule($trip, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->subMinute(),
        ]);
        $this->assertFalse($lapsed->flashSaleActive());
        $this->assertSame(3000.0, $lapsed->effective_price);

        // Sold out → not sellable, so no flash price.
        $soldOut = $this->makeSchedule($trip, [
            'total_seats' => 2, 'booked_seats' => 2,
            'flash_sale_enabled' => true, 'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->addDay(),
        ]);
        $this->assertFalse($soldOut->flashSaleActive());
        $this->assertSame(3000.0, $soldOut->effective_price);
    }

    public function test_trip_resource_exposes_flash_sale_block(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->addDay(),
        ]);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.is_flash_sale', true)
            ->assertJsonPath('data.min_price', 1990)
            ->assertJsonPath('data.schedules.0.price', 1990)
            ->assertJsonPath('data.schedules.0.original_price', 3000)
            ->assertJsonPath('data.schedules.0.flash_sale.active', true)
            ->assertJsonPath('data.schedules.0.flash_sale.discount_percent', 34);
    }

    public function test_enabling_flash_sale_broadcasts_once(): void
    {
        $trip = $this->makeTrip();

        // Created with a live flash sale → one broadcast.
        $schedule = $this->makeSchedule($trip, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->addDay(),
        ]);
        $this->assertSame(1, BroadcastDispatch::where('event_type', 'flash_sale')->count());

        // An unrelated edit must not re-announce the same sale.
        $schedule->update(['total_seats' => 12]);
        $this->assertSame(1, BroadcastDispatch::where('event_type', 'flash_sale')->count());

        // Moving the end time is a new sale window → re-announce.
        $schedule->update(['flash_sale_ends_at' => now()->addDays(2)]);
        $this->assertSame(2, BroadcastDispatch::where('event_type', 'flash_sale')->count());
    }

    public function test_flash_sale_endpoint_lists_only_active_soonest_first(): void
    {
        $soon = $this->makeTrip('Soon Flash');
        $this->makeSchedule($soon, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1500,
            'flash_sale_ends_at' => now()->addHours(2),
        ]);

        $later = $this->makeTrip('Later Flash');
        $this->makeSchedule($later, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1800,
            'flash_sale_ends_at' => now()->addDays(3),
        ]);

        $noFlash = $this->makeTrip('No Flash');
        $this->makeSchedule($noFlash);

        $this->getJson('/api/v1/trips/flash-sale')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', $soon->slug)   // soonest-ending first
            ->assertJsonPath('data.1.slug', $later->slug);
    }
}
