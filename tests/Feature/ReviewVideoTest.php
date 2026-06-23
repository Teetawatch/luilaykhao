<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * รีวิวแนบวิดีโอ — ลูกค้าอัปโหลดวิดีโอขึ้น R2 และแนบ URL มากับรีวิว, ระบบส่งกลับใน payload
 */
class ReviewVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.disks.r2.bucket', null);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeReviewableBooking(User $user): Booking
    {
        $trip = Trip::create([
            'title' => 'Video Review Trip',
            'slug' => 'video-review-trip',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-05-08',
            'return_date' => '2026-05-08',
            'total_seats' => 8,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        // 20:00 Bangkok on the return day — the review window is open.
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        return Booking::create([
            'booking_ref' => Booking::generateRef().'-'.uniqid(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
        ]);
    }

    public function test_customer_can_upload_a_review_video(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/upload-video', [
                'video' => UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
            ])
            ->assertOk();

        $url = $response->json('data.url');
        $this->assertNotEmpty($url);
    }

    public function test_upload_rejects_non_video_files(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/upload-video', [
                'video' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_review_stores_and_returns_attached_videos(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeReviewableBooking($user);

        $videos = ['https://media.luilaykhao.com/reviews/videos/clip.mp4'];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'สนุกมากครับ',
                'videos' => $videos,
            ])
            ->assertCreated()
            ->assertJsonPath('data.videos', $videos);

        $this->assertSame($videos, Review::first()->videos);
    }
}
