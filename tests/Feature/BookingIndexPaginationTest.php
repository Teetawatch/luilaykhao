<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingIndexPaginationTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeBooking(User $user, string $status): Booking
    {
        $trip = Trip::create([
            'title' => 'ทริป '.uniqid(),
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'northeast',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(11)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 2500,
            'paid_amount' => 0,
        ]);
    }

    public function test_without_per_page_it_still_returns_everything(): void
    {
        $user = User::factory()->create();
        foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $status) {
            $this->makeBooking($user, $status);
        }

        // แอปมือถือที่ปล่อยไปแล้วเรียกแบบไม่มีพารามิเตอร์และคาดหวังรายการครบ
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/bookings')->assertOk();

        $this->assertCount(4, $response->json('data'));
        $this->assertNull($response->json('meta.current_page'));
    }

    public function test_per_page_switches_to_pagination(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->makeBooking($user, 'confirmed');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_scope_filters_to_the_matching_tab(): void
    {
        $user = User::factory()->create();
        $this->makeBooking($user, 'pending');
        $this->makeBooking($user, 'confirmed');
        $this->makeBooking($user, 'completed');
        $this->makeBooking($user, 'cancelled');
        $this->makeBooking($user, 'refunded');

        $upcoming = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=upcoming')->assertOk()->json('data');
        $past = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=past')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['pending', 'confirmed'],
            array_column($upcoming, 'status'),
        );
        $this->assertEqualsCanonicalizing(
            ['completed', 'cancelled', 'refunded'],
            array_column($past, 'status'),
        );
    }

    public function test_tab_counts_cover_every_booking_not_just_the_current_page(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 4) as $i) {
            $this->makeBooking($user, 'confirmed');
        }
        foreach (range(1, 3) as $i) {
            $this->makeBooking($user, 'completed');
        }

        // ดูแท็บ "กำลังจะมาถึง" หน้าละ 1 รายการ — ตัวเลขทั้งสองแท็บต้องยังเต็มจำนวน
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=upcoming&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.upcoming_count', 4)
            ->assertJsonPath('meta.past_count', 3)
            ->assertJsonPath('meta.total', 4);
    }

    public function test_counts_only_cover_the_callers_own_bookings(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $this->makeBooking($user, 'confirmed');
        $this->makeBooking($stranger, 'confirmed');
        $this->makeBooking($stranger, 'completed');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=upcoming')
            ->assertOk()
            ->assertJsonPath('meta.upcoming_count', 1)
            ->assertJsonPath('meta.past_count', 0)
            ->assertJsonCount(1, 'data');
    }

    public function test_rejects_a_nonsense_scope_or_page_size(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/bookings?scope=banana')->assertStatus(422);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/bookings?per_page=999')->assertStatus(422);
    }
}
