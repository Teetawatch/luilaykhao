<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * สรุปคะแนนรีวิว — ค่าเฉลี่ยรวม, การกระจายดาว 1-5 และค่าเฉลี่ยรายหมวด
 * สำหรับหน้ารีวิวสาธารณะ
 */
class ReviewStatsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $slug = 'stats-trip'): Trip
    {
        return Trip::create([
            'title' => 'Stats Trip',
            'slug' => $slug,
            'type' => 'trekking',
            'location' => 'Nan',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);
    }

    private function makeReview(Trip $trip, User $user, array $attributes): Review
    {
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-05-08',
            'return_date' => '2026-05-08',
            'total_seats' => 8,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
        ]);

        return Review::create(array_merge([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'trip_id' => $trip->id,
            'is_approved' => true,
        ], $attributes));
    }

    public function test_stats_report_average_distribution_and_category_averages(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        $this->makeReview($trip, $user, [
            'rating' => 5, 'rating_guide' => 5, 'rating_food' => 4,
            'images' => ['a.jpg'], 'admin_reply' => 'ขอบคุณครับ',
        ]);
        $this->makeReview($trip, $user, ['rating' => 5, 'rating_guide' => 4]);
        $this->makeReview($trip, $user, ['rating' => 3]);
        // ยังไม่อนุมัติ — ต้องไม่ถูกนับ
        $this->makeReview($trip, $user, ['rating' => 1, 'is_approved' => false]);

        $data = $this->getJson('/api/v1/reviews/stats')->assertOk()->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertEqualsWithDelta(4.33, $data['average'], 0.01);
        $this->assertSame(2, $data['distribution']['5']);
        $this->assertSame(1, $data['distribution']['3']);
        $this->assertSame(0, $data['distribution']['1']);
        $this->assertSame(1, $data['with_media']);
        $this->assertSame(1, $data['with_reply']);

        // ค่าเฉลี่ยไกด์คิดจากรีวิว 2 รายการที่ให้คะแนน (5,4)
        $this->assertSame(2, $data['categories']['guide']['count']);
        $this->assertEqualsWithDelta(4.5, $data['categories']['guide']['average'], 0.01);
        // ไม่มีใครให้คะแนนยานพาหนะ
        $this->assertSame(0, $data['categories']['vehicle']['count']);
        $this->assertNull($data['categories']['vehicle']['average']);
    }

    public function test_reviews_saved_with_empty_media_arrays_do_not_count_as_with_media(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        // ReviewController::store บันทึก [] เมื่อไม่ได้แนบอะไร
        $this->makeReview($trip, $user, ['rating' => 5, 'images' => [], 'videos' => []]);
        $this->makeReview($trip, $user, ['rating' => 4, 'images' => [], 'videos' => ['v.mp4']]);
        $this->makeReview($trip, $user, ['rating' => 4, 'images' => ['a.jpg'], 'videos' => []]);

        $data = $this->getJson('/api/v1/reviews/stats')->assertOk()->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['with_media']);
    }

    public function test_index_can_filter_to_reviews_with_photos_or_videos(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        $this->makeReview($trip, $user, ['rating' => 5, 'images' => [], 'videos' => []]);
        $withPhoto = $this->makeReview($trip, $user, ['rating' => 5, 'images' => ['a.jpg']]);
        $withVideo = $this->makeReview($trip, $user, ['rating' => 3, 'videos' => ['v.mp4']]);

        $ids = collect($this->getJson('/api/v1/reviews?has_media=1')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
        $this->assertSame([$withPhoto->id, $withVideo->id], $ids);

        // รวมกับตัวกรองดาวได้
        $ids = collect($this->getJson('/api/v1/reviews?has_media=1&rating=5')->json('data'))->pluck('id')->all();
        $this->assertSame([$withPhoto->id], $ids);

        $this->assertCount(3, $this->getJson('/api/v1/reviews')->json('data'));
    }

    public function test_stats_leave_out_authors_the_viewer_blocked_like_the_list_does(): void
    {
        $trip = $this->makeTrip();
        $viewer = User::factory()->create();
        $blocked = User::factory()->create();
        $other = User::factory()->create();

        $this->makeReview($trip, $blocked, ['rating' => 1]);
        $this->makeReview($trip, $other, ['rating' => 5]);
        UserBlock::create(['blocker_id' => $viewer->id, 'blocked_id' => $blocked->id]);

        $this->assertSame(2, $this->getJson('/api/v1/reviews/stats')->json('data.total'));

        $data = $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/reviews/stats')->json('data');
        $this->assertSame(1, $data['total']);
        $this->assertSame(0, $data['distribution']['1']);
    }

    public function test_stats_can_scope_to_a_single_trip(): void
    {
        $tripA = $this->makeTrip('trip-a');
        $tripB = $this->makeTrip('trip-b');
        $user = User::factory()->create();

        $this->makeReview($tripA, $user, ['rating' => 5]);
        $this->makeReview($tripB, $user, ['rating' => 2]);

        $data = $this->getJson('/api/v1/reviews/stats?trip_id='.$tripA->id)->assertOk()->json('data');

        $this->assertSame(1, $data['total']);
        $this->assertEqualsWithDelta(5.0, $data['average'], 0.01);
    }

    public function test_stats_are_zeroed_when_there_are_no_reviews(): void
    {
        $data = $this->getJson('/api/v1/reviews/stats')->assertOk()->json('data');

        $this->assertSame(0, $data['total']);
        $this->assertEqualsWithDelta(0.0, $data['average'], 0.001);
        $this->assertSame(0, $data['distribution']['5']);
    }
}
