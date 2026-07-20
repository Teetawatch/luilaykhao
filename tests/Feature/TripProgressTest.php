<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ScheduleItineraryItem;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripProgressTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Doi Inthanon',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $departure = now('Asia/Bangkok')->toDateString();

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

    private function makeBooking(TripSchedule $schedule, User $user, string $status = 'confirmed'): Booking
    {
        return Booking::create([
            'booking_ref' => sprintf('LLK-20200101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    /** @param array<int, array{title: string, reached: bool}> $specs */
    private function addItinerary(TripSchedule $schedule, array $specs): array
    {
        $items = [];
        foreach ($specs as $i => $spec) {
            $items[] = ScheduleItineraryItem::create([
                'schedule_id' => $schedule->id,
                'item_date' => $schedule->departure_date,
                'time' => sprintf('%02d:00', 6 + $i),
                'title' => $spec['title'],
                'sort_order' => $i,
                'reached_at' => $spec['reached'] ? now()->subMinutes(60 - $i * 10) : null,
            ]);
        }

        return $items;
    }

    public function test_progress_reports_current_next_and_percent(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, $user);

        $this->addItinerary($schedule, [
            ['title' => 'จุดเริ่มต้น', 'reached' => true],
            ['title' => 'น้ำตกชั้น 3', 'reached' => true],
            ['title' => 'แคมป์', 'reached' => false],
            ['title' => 'ยอดดอย', 'reached' => false],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/progress")
            ->assertOk()
            ->assertJsonPath('data.progress.total', 4)
            ->assertJsonPath('data.progress.reached_count', 2)
            ->assertJsonPath('data.progress.percent', 50)
            ->assertJsonPath('data.progress.current.title', 'น้ำตกชั้น 3')
            ->assertJsonPath('data.progress.current.is_current', true)
            ->assertJsonPath('data.progress.next.title', 'แคมป์');
    }

    public function test_schedule_without_itinerary_reports_no_itinerary(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeBooking($this->makeSchedule(), $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/progress")
            ->assertOk()
            ->assertJsonPath('data.progress.has_itinerary', false)
            ->assertJsonPath('data.progress.total', 0)
            ->assertJsonPath('data.progress.current', null);
    }

    public function test_current_follows_check_in_time_not_list_order(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, $user);

        $items = $this->addItinerary($schedule, [
            ['title' => 'จุด A', 'reached' => false],
            ['title' => 'จุด B', 'reached' => false],
        ]);

        // ทีมงานกดจุด B ก่อน แล้วค่อยย้อนกดจุด A — จุดปัจจุบันต้องเป็น A
        $items[1]->update(['reached_at' => now()->subMinutes(30)]);
        $items[0]->update(['reached_at' => now()->subMinutes(5)]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/progress")
            ->assertOk()
            ->assertJsonPath('data.progress.current.title', 'จุด A');
    }

    public function test_companion_can_view_progress(): void
    {
        $owner = User::factory()->create();
        $companion = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, $owner);

        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->addItinerary($schedule, [['title' => 'จุดเริ่มต้น', 'reached' => true]]);

        $this->actingAs($companion, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/progress")
            ->assertOk()
            ->assertJsonPath('data.progress.reached_count', 1);
    }

    public function test_stranger_cannot_view_progress(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeBooking($this->makeSchedule(), $owner);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/progress")
            ->assertStatus(403);
    }

    public function test_progress_requires_authentication(): void
    {
        $booking = $this->makeBooking($this->makeSchedule(), User::factory()->create());

        $this->getJson("/api/v1/bookings/{$booking->booking_ref}/progress")
            ->assertStatus(401);
    }

    // ── ลิงก์ให้ที่บ้านติดตาม ─────────────────────────────────────────────────

    public function test_share_link_exposes_milestones_without_coordinates(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, $user);
        $token = $booking->ensureShareToken();

        $this->addItinerary($schedule, [
            ['title' => 'ออกเดินทาง', 'reached' => true],
            ['title' => 'ถึงแคมป์', 'reached' => false],
        ]);

        $res = $this->getJson("/api/v1/track/{$token}/progress")
            ->assertOk()
            ->assertJsonPath('data.cancelled', false)
            ->assertJsonPath('data.progress.reached_count', 1)
            ->assertJsonPath('data.progress.current.title', 'ออกเดินทาง');

        // ต้องไม่มีพิกัดหลุดออกไปกับ payload นี้เลย
        $body = $res->getContent();
        $this->assertStringNotContainsString('lat', $body);
        $this->assertStringNotContainsString('lng', $body);
    }

    public function test_share_link_hides_progress_for_cancelled_booking(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, $user, 'cancelled');
        $token = $booking->ensureShareToken();

        $this->addItinerary($schedule, [['title' => 'ออกเดินทาง', 'reached' => true]]);

        $this->getJson("/api/v1/track/{$token}/progress")
            ->assertOk()
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.progress', null);
    }

    public function test_unknown_share_token_is_404(): void
    {
        $this->getJson('/api/v1/track/doesnotexist/progress')
            ->assertStatus(404);
    }
}
