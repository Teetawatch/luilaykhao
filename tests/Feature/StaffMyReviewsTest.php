<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\StaffReview;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รีวิวที่ลูกค้าเขียนถึงสตาฟ — หน้า "คะแนนเฉลี่ย" ในแท็บงานสตาฟของแอป
 *
 * สตาฟต้องเห็นเฉพาะรีวิวของตัวเอง พร้อมคอมเมนต์ และภาพรวมดาวต้องนับจากรีวิว
 * ทั้งหมด ไม่ใช่แค่หน้าที่โหลดมา
 */
class StaffMyReviewsTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $trip = Trip::create([
            'title' => 'ภูชี้ฟ้า', 'slug' => 'smr-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงราย', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 20, 'price_per_person' => 2200, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->subDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(2)->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    private function review(User $staff, int $rating, ?string $comment = null, string $reviewerName = 'ลูกค้า'): StaffReview
    {
        $reviewer = User::factory()->create(['name' => $reviewerName]);
        $reviewer->assignRole('customer');

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $reviewer->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'completed',
            'total_amount' => 2200,
            'paid_amount' => 2200,
            'payment_type' => 'full',
        ]);

        return StaffReview::create([
            'booking_id' => $booking->id,
            'schedule_id' => $this->schedule->id,
            'reviewer_user_id' => $reviewer->id,
            'staff_user_id' => $staff->id,
            'rating' => $rating,
            'comment' => $comment,
        ]);
    }

    public function test_staff_reads_only_their_own_reviews_with_comments(): void
    {
        $me = $this->staff();
        $other = $this->staff();

        $this->review($me, 5, 'พี่ดูแลดีมาก', 'สมศรี');
        $this->review($other, 1, 'ไม่ใช่ของคุณ');

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/staff/reviews/my')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rating', 5)
            ->assertJsonPath('data.0.comment', 'พี่ดูแลดีมาก')
            ->assertJsonPath('data.0.reviewer_name', 'สมศรี')
            ->assertJsonPath('data.0.trip_title', 'ภูชี้ฟ้า')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_summary_counts_every_review_not_just_the_page(): void
    {
        $me = $this->staff();
        $this->review($me, 5, 'เยี่ยม');
        $this->review($me, 5);
        $this->review($me, 4, 'ดี');
        $this->review($me, 2, 'รอนาน');

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/staff/reviews/my?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.last_page', 4)
            ->assertJsonPath('meta.summary.total_reviews', 4)
            ->assertJsonPath('meta.summary.avg_rating', 4)
            ->assertJsonPath('meta.summary.breakdown', ['5' => 2, '4' => 1, '3' => 0, '2' => 1, '1' => 0]);
    }

    public function test_summary_is_empty_for_staff_without_reviews(): void
    {
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/staff/reviews/my')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.summary.total_reviews', 0)
            ->assertJsonPath('meta.summary.avg_rating', null);
    }

    public function test_per_page_is_capped(): void
    {
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/staff/reviews/my?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_non_staff_is_refused(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/staff/reviews/my')->assertForbidden();
    }
}
