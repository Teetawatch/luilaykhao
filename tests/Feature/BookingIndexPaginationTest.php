<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingIndexPaginationTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeBooking(User $user, string $status, int $departIn = 10, int $returnIn = 11, string $location = 'เลย'): Booking
    {
        $trip = Trip::create([
            'title' => 'ทริป '.uniqid(),
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => $location,
            'region' => 'northeast',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($departIn)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($returnIn)->toDateString(),
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

    /** แอปแบ่งตามวันที่ — ใบที่เดินทางจบแล้วยัง confirmed อยู่ต้องไปอยู่ในประวัติ */
    public function test_current_and_history_split_by_date_not_status(): void
    {
        $user = User::factory()->create();
        $future = $this->makeBooking($user, 'confirmed', 5, 6);
        $onTripNow = $this->makeBooking($user, 'confirmed', -1, 0);
        $pendingSoon = $this->makeBooking($user, 'pending', 3, 3);
        $endedConfirmed = $this->makeBooking($user, 'confirmed', -10, -9);
        $endedYesterday = $this->makeBooking($user, 'confirmed', -1, -1);
        $cancelledFuture = $this->makeBooking($user, 'cancelled', 20, 21);
        $completed = $this->makeBooking($user, 'completed', -30, -29);

        $current = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=current')->assertOk()->json('data');
        $history = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=history&per_page=50')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            [$future->booking_ref, $onTripNow->booking_ref, $pendingSoon->booking_ref],
            array_column($current, 'booking_ref'),
        );
        $this->assertEqualsCanonicalizing(
            [$endedConfirmed->booking_ref, $endedYesterday->booking_ref, $cancelledFuture->booking_ref, $completed->booking_ref],
            array_column($history, 'booking_ref'),
        );
    }

    public function test_a_booking_awaiting_a_new_round_stays_current_even_after_its_date(): void
    {
        $user = User::factory()->create();
        $postponed = $this->makeBooking($user, 'confirmed', -5, -4);
        $postponed->forceFill(['force_majeure_at' => now()->subDays(6)])->save();

        $resolved = $this->makeBooking($user, 'confirmed', -5, -4);
        $resolved->forceFill(['force_majeure_at' => now()->subDays(6), 'force_majeure_resolved_at' => now()->subDays(5)])->save();

        $current = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=current')->assertOk()->json('data');

        $this->assertSame([$postponed->booking_ref], array_column($current, 'booking_ref'));
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=history')->assertOk()
            ->assertJsonPath('data.0.booking_ref', $resolved->booking_ref)
            ->assertJsonCount(1, 'data');
    }

    public function test_history_pages_by_travel_date_newest_first(): void
    {
        $user = User::factory()->create();
        // จองตามลำดับนี้ — วันที่จองไม่ตรงกับวันเดินทาง
        $old = $this->makeBooking($user, 'confirmed', -40, -39);
        $recent = $this->makeBooking($user, 'confirmed', -3, -2);
        $middle = $this->makeBooking($user, 'completed', -20, -19);

        $page1 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=history&per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->json('data');
        $page2 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=history&per_page=2&page=2')
            ->assertOk()->json('data');

        $this->assertSame([$recent->booking_ref, $middle->booking_ref], array_column($page1, 'booking_ref'));
        $this->assertSame([$old->booking_ref], array_column($page2, 'booking_ref'));
    }

    public function test_history_meta_counts_cover_every_page(): void
    {
        $user = User::factory()->create();
        $this->makeBooking($user, 'confirmed', 5, 6);
        $this->makeBooking($user, 'confirmed', -10, -9, 'เลย');
        $this->makeBooking($user, 'completed', -30, -29, 'เลย');
        $this->makeBooking($user, 'confirmed', -50, -49, 'น่าน');
        $this->makeBooking($user, 'cancelled', 8, 9, 'ตาก');
        $this->makeBooking($user, 'refunded', -8, -7, 'ตาก');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings?scope=history&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_count', 1)
            ->assertJsonPath('meta.history_count', 5)
            ->assertJsonPath('meta.travelled_count', 3)
            ->assertJsonPath('meta.cancelled_count', 2)
            // ยกเลิกไม่นับเป็นที่ที่เคยไป
            ->assertJsonPath('meta.destinations_count', 2);
    }

    public function test_history_includes_bookings_as_a_companion(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $booking = $this->makeBooking($owner, 'confirmed', -10, -9);
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($friend, 'sanctum')
            ->getJson('/api/v1/bookings?scope=history')
            ->assertOk()
            ->assertJsonPath('data.0.booking_ref', $booking->booking_ref)
            ->assertJsonPath('meta.travelled_count', 1);
    }
}
