<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\CommunityStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommunityStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    /** สร้างรอบเดินทาง + การจอง โดยคุมวันเดินทาง/ที่นั่งที่จองได้เพื่อทดสอบการรวมสถิติ. */
    private function makeRound(array $tripOverrides = [], int $bookedSeats = 1, ?string $departure = null, string $scheduleStatus = 'open'): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'Doi '.uniqid(),
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Rai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'distance_km' => 20,
            'elevation_gain_m' => 1000,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $tripOverrides));

        $departure = $departure ?? now('Asia/Bangkok')->subDays(5)->toDateString();

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 20,
            'booked_seats' => $bookedSeats,
            'transport_type' => 'van',
            'status' => $scheduleStatus,
        ]);
    }

    private function bookOn(TripSchedule $schedule, User $user, string $status = 'completed'): Booking
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

    public function test_distance_and_elevation_are_summed_per_person(): void
    {
        // 20 กม. × 4 ที่นั่ง + 10 กม. × 2 ที่นั่ง = 100 person-km
        $this->makeRound(['distance_km' => 20, 'elevation_gain_m' => 1000], bookedSeats: 4);
        $this->makeRound(['distance_km' => 10, 'elevation_gain_m' => 500, 'region' => 'south'], bookedSeats: 2);

        $stats = app(CommunityStatsService::class)->get();

        $this->assertSame(100.0, $stats['total_distance_km']);
        $this->assertSame(5000, $stats['total_elevation_gain_m']);
        $this->assertSame(2, $stats['rounds_completed']);
        $this->assertSame(6, $stats['seats_travelled']);
        $this->assertSame(2, $stats['regions_count']);
    }

    public function test_future_and_cancelled_rounds_are_excluded(): void
    {
        $this->makeRound(['distance_km' => 20], bookedSeats: 2);
        // รอบที่ยังไม่ออกเดินทาง — ยังไม่นับเป็นระยะทางสะสม
        $this->makeRound(['distance_km' => 999], bookedSeats: 5, departure: now('Asia/Bangkok')->addDays(10)->toDateString());
        // รอบที่ยกเลิก — ไม่นับ
        $this->makeRound(['distance_km' => 999], bookedSeats: 5, scheduleStatus: 'cancelled');

        $stats = app(CommunityStatsService::class)->get();

        $this->assertSame(40.0, $stats['total_distance_km']);
        $this->assertSame(1, $stats['rounds_completed']);
    }

    public function test_travellers_are_counted_once_across_bookings(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $a = $this->makeRound(bookedSeats: 2);
        $b = $this->makeRound(bookedSeats: 1);

        $this->bookOn($a, $user);
        $this->bookOn($b, $user); // คนเดิม จองสองรอบ — ต้องนับเป็น 1 คน
        $this->bookOn($b, $other);
        // การจองที่ถูกยกเลิกไม่นับเป็นผู้เดินทาง
        $this->bookOn($a, User::factory()->create(), status: 'cancelled');

        $this->assertSame(2, app(CommunityStatsService::class)->get()['travellers_count']);
    }

    public function test_public_stats_endpoint_exposes_community_block(): void
    {
        // /stats นับลูกค้าด้วย role — ต้องมี role อยู่จริงก่อนเรียก
        Role::findOrCreate('customer', 'web');

        $this->makeRound(['distance_km' => 12.5, 'elevation_gain_m' => 2565], bookedSeats: 2);

        $this->getJson('/api/v1/stats')
            ->assertOk()
            // ค่าลงตัวถูก JSON encode เป็น int (25.0 → 25)
            ->assertJsonPath('data.community.total_distance_km', 25)
            ->assertJsonPath('data.community.total_elevation_gain_m', 5130)
            ->assertJsonPath('data.community.highlights.inthanon_multiple', 2);
    }
}
