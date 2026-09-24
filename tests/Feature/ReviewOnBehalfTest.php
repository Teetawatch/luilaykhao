<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แอดมินจองให้ลูกค้าจากบัญชีตัวเอง แล้วรีวิวแทนลูกค้าหลังจบทริป — รีวิวต้อง
 * ขึ้นเป็นชื่อลูกค้า ไม่ใช่ชื่อแอดมิน
 */
class ReviewOnBehalfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(?string $role = null, string $name = 'บัญชีผู้จอง'): User
    {
        $user = User::factory()->create(['name' => $name]);
        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    /** @return array{0: Booking, 1: BookingPassenger, 2: BookingPassenger} */
    private function bookingOwnedBy(User $owner): array
    {
        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
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
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 0,
        ]);

        $lead = BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'สมหญิง ใจดี']);
        $friend = BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'สมชาย รักเดิน']);

        return [$booking, $lead, $friend];
    }

    private function review(User $as, Booking $booking, array $extra = [])
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/v1/reviews', [
            'booking_id' => $booking->id,
            'rating' => 5,
            'comment' => 'ทริปดีมากครับ',
            ...$extra,
        ]);
    }

    public function test_admin_booked_review_defaults_to_the_lead_passenger_name(): void
    {
        $admin = $this->user('admin', 'แอดมิน ลุยเลเขา');
        [$booking] = $this->bookingOwnedBy($admin);

        $this->review($admin, $booking)
            ->assertCreated()
            ->assertJsonPath('data.user_name', 'สมหญิง ใจดี')
            ->assertJsonPath('data.user.name', 'สมหญิง ใจดี')
            ->assertJsonPath('data.user_avatar', null);

        $this->assertDatabaseHas('reviews', [
            'booking_id' => $booking->id,
            'user_id' => $admin->id,
            'reviewer_name' => 'สมหญิง ใจดี',
        ]);

        // หน้ารีวิวสาธารณะก็ต้องเห็นชื่อลูกค้า
        $this->getJson('/api/v1/reviews?trip_id='.$booking->schedule->trip_id)
            ->assertOk()
            ->assertJsonPath('data.0.user_name', 'สมหญิง ใจดี');
    }

    public function test_admin_can_pick_which_passenger_the_review_is_from(): void
    {
        $operator = $this->user('operator');
        [$booking, , $friend] = $this->bookingOwnedBy($operator);

        $this->review($operator, $booking, ['passenger_id' => $friend->id])
            ->assertCreated()
            ->assertJsonPath('data.user_name', 'สมชาย รักเดิน');
    }

    public function test_passenger_from_another_booking_is_rejected(): void
    {
        $admin = $this->user('admin');
        [$booking] = $this->bookingOwnedBy($admin);
        [, $stranger] = $this->bookingOwnedBy($this->user());

        $this->review($admin, $booking, ['passenger_id' => $stranger->id])
            ->assertStatus(422);

        $this->assertSame(0, Review::count());
    }

    public function test_a_regular_customer_still_reviews_under_their_own_name(): void
    {
        $customer = $this->user(null, 'ลูกค้า ตัวจริง');
        [$booking, , $friend] = $this->bookingOwnedBy($customer);

        // passenger_id ถูกเมินสำหรับบัญชีลูกค้าทั่วไป
        $this->review($customer, $booking, ['passenger_id' => $friend->id])
            ->assertCreated()
            ->assertJsonPath('data.user_name', 'ลูกค้า ตัวจริง');

        $this->assertNull(Review::first()->reviewer_name);
    }

    public function test_booking_resource_offers_passengers_to_review_as_only_to_the_admin_owner(): void
    {
        $admin = $this->user('admin');
        [$booking, $lead, $friend] = $this->bookingOwnedBy($admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bookings/'.$booking->booking_ref)
            ->assertOk()
            ->assertJsonPath('data.review_as', [
                ['passenger_id' => $lead->id, 'name' => 'สมหญิง ใจดี'],
                ['passenger_id' => $friend->id, 'name' => 'สมชาย รักเดิน'],
            ]);

        $customer = $this->user();
        [$own] = $this->bookingOwnedBy($customer);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/bookings/'.$own->booking_ref)
            ->assertOk()
            ->assertJsonMissingPath('data.review_as');
    }

    public function test_admin_review_list_shows_who_posted_it(): void
    {
        $admin = $this->user('admin', 'แอดมิน ลุยเลเขา');
        [$booking] = $this->bookingOwnedBy($admin);
        $this->review($admin, $booking)->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/reviews')
            ->assertOk()
            ->assertJsonPath('data.0.user_name', 'สมหญิง ใจดี')
            ->assertJsonPath('data.0.posted_by', 'แอดมิน ลุยเลเขา');
    }
}
