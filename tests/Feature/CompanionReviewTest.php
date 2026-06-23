<?php

namespace Tests\Feature;

use App\Jobs\SendReviewInvitesJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Review;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * เพื่อนร่วมเดินทาง (companion) ที่รับคำเชิญเข้าการจองเดียวกัน ต้องรีวิวได้เหมือนเจ้าของ
 * โดยแยกรีวิวรายคน (คนละ 1 ครั้ง) และระบบแจ้งเตือนให้รีวิวครบทุกคน
 */
class CompanionReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: Booking, 1: User, 2: User} [booking, owner, companion] */
    private function bookingWithCompanion(): array
    {
        $owner = User::factory()->create();
        $companion = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Group Trip',
            'slug' => 'group-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Pai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-05-07',
            'return_date' => '2026-05-08',
            'total_seats' => 10,
            'booked_seats' => 4,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef().'-'.uniqid(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 6000,
            'paid_amount' => 6000,
        ]);

        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'invited_by' => $owner->id,
            'accepted_at' => now(),
        ]);

        return [$booking, $owner, $companion];
    }

    public function test_companion_can_review_the_shared_booking(): void
    {
        [$booking, , $companion] = $this->bookingWithCompanion();
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        $this->actingAs($companion, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'เพื่อนรีวิวได้แล้ว',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('reviews', [
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
        ]);
    }

    public function test_owner_and_companion_each_review_once(): void
    {
        [$booking, $owner, $companion] = $this->bookingWithCompanion();
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        $payload = ['booking_id' => $booking->id, 'rating' => 5, 'comment' => 'ดีมาก'];

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reviews', $payload)->assertCreated();
        $this->actingAs($companion, 'sanctum')
            ->postJson('/api/v1/reviews', $payload)->assertCreated();

        // Two distinct reviews on the same booking.
        $this->assertSame(2, Review::where('booking_id', $booking->id)->count());

        // Neither may review twice.
        $this->actingAs($companion, 'sanctum')
            ->postJson('/api/v1/reviews', $payload)->assertStatus(422);
    }

    public function test_outsider_cannot_review_the_booking(): void
    {
        [$booking] = $this->bookingWithCompanion();
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'ไม่ได้ไปด้วย',
            ])
            ->assertStatus(403);
    }

    public function test_can_review_flag_is_per_traveller(): void
    {
        [$booking, $owner, $companion] = $this->bookingWithCompanion();
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        // Owner reviews.
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'เจ้าของรีวิว',
            ])->assertCreated();

        // Owner's list: can_review now false.
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.can_review', false);

        // Companion's list: still true — they haven't reviewed yet.
        $this->actingAs($companion, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.can_review', true);
    }

    public function test_review_invite_job_notifies_owner_and_companion(): void
    {
        [$booking, $owner, $companion] = $this->bookingWithCompanion();
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        (new SendReviewInvitesJob)->handle();

        foreach ([$owner->id, $companion->id] as $userId) {
            $this->assertSame(
                1,
                SmartNotification::where('user_id', $userId)
                    ->where('type', 'review_invite')
                    ->where('data->booking_ref', $booking->booking_ref)
                    ->count(),
            );
        }

        // Running again sends no duplicates.
        (new SendReviewInvitesJob)->handle();
        $this->assertSame(2, SmartNotification::where('type', 'review_invite')->count());
    }
}
