<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * อัลบั้มภาพจากผู้ร่วมทริป — รวมรูปจากทุกรีวิวที่อนุมัติแล้วของทริปมาแสดงเป็นแกลเลอรีเดียว
 */
class ReviewPhotoAlbumTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeTrip(string $slug = 'album-trip'): Trip
    {
        return Trip::create([
            'title' => 'Album Trip',
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

    private function makeBooking(Trip $trip, User $user): Booking
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

        return Booking::create([
            'booking_ref' => Booking::generateRef().'-'.uniqid(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
        ]);
    }

    /** @param  list<string>  $images */
    private function makeReview(Trip $trip, User $user, array $images, bool $approved = true, ?string $at = null): Review
    {
        $review = Review::create([
            'user_id' => $user->id,
            'booking_id' => $this->makeBooking($trip, $user)->id,
            'trip_id' => $trip->id,
            'rating' => 5,
            'comment' => 'ดีมาก',
            'images' => $images,
            'is_approved' => $approved,
        ]);

        if ($at) {
            $review->forceFill(['created_at' => $at])->save();
        }

        return $review;
    }

    public function test_album_flattens_images_from_every_approved_review_newest_first(): void
    {
        $trip = $this->makeTrip();
        $older = User::factory()->create(['name' => 'สมชาย']);
        $newer = User::factory()->create(['name' => 'สมหญิง']);

        $this->makeReview($trip, $older, ['a1.jpg', 'a2.jpg'], at: '2026-05-01 10:00:00');
        $this->makeReview($trip, $newer, ['b1.jpg'], at: '2026-05-09 10:00:00');

        $res = $this->getJson('/api/v1/reviews/photos?trip_id='.$trip->id)->assertOk();

        $data = $res->json('data');
        $this->assertSame(3, $data['total']);
        $this->assertFalse($data['has_more']);
        $this->assertSame(['b1.jpg', 'a1.jpg', 'a2.jpg'], array_column($data['photos'], 'url'));
        $this->assertSame('สมหญิง', $data['photos'][0]['user_name']);
        $this->assertSame(5, $data['photos'][0]['rating']);
        $this->assertNotNull($data['photos'][0]['user_avatar']);
    }

    public function test_album_excludes_unapproved_reviews_reviews_without_images_and_other_trips(): void
    {
        $trip = $this->makeTrip();
        $otherTrip = $this->makeTrip('other-album-trip');
        $user = User::factory()->create();

        $this->makeReview($trip, $user, ['visible.jpg']);
        $this->makeReview($trip, $user, ['hidden.jpg'], approved: false);
        $this->makeReview($trip, $user, []);
        $this->makeReview($otherTrip, $user, ['elsewhere.jpg']);

        $data = $this->getJson('/api/v1/reviews/photos?trip_id='.$trip->id)->assertOk()->json('data');

        $this->assertSame(['visible.jpg'], array_column($data['photos'], 'url'));
        $this->assertSame(1, $data['total']);
    }

    public function test_album_paginates_per_photo_not_per_review(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        // รีวิวเดียวแต่มี 4 รูป — ต้องแบ่งหน้าได้ตามจำนวนรูป
        $this->makeReview($trip, $user, ['1.jpg', '2.jpg', '3.jpg', '4.jpg']);

        $first = $this->getJson('/api/v1/reviews/photos?trip_id='.$trip->id.'&per_page=3')->assertOk()->json('data');
        $this->assertSame(['1.jpg', '2.jpg', '3.jpg'], array_column($first['photos'], 'url'));
        $this->assertTrue($first['has_more']);
        $this->assertSame(4, $first['total']);

        $second = $this->getJson('/api/v1/reviews/photos?trip_id='.$trip->id.'&per_page=3&page=2')->assertOk()->json('data');
        $this->assertSame(['4.jpg'], array_column($second['photos'], 'url'));
        $this->assertFalse($second['has_more']);
    }

    public function test_album_requires_an_existing_trip(): void
    {
        $this->getJson('/api/v1/reviews/photos')->assertStatus(422);
        $this->getJson('/api/v1/reviews/photos?trip_id=999999')->assertStatus(422);
    }
}
