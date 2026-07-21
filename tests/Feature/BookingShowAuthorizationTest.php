<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingShowAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(User $owner): Booking
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
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

        $booking = Booking::create([
            'booking_ref' => 'LLK-20260101-0001',
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'สมชาย ใจดี',
            'phone' => '0812345678',
        ]);

        return $booking;
    }

    public function test_a_stranger_cannot_read_someone_elses_booking(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com', 'phone' => '0899999999']);
        $stranger = User::factory()->create();
        $booking = $this->makeBooking($owner);

        // booking_ref เดาได้ไม่ยาก (LLK-วันที่-เลขสี่หลัก) จึงต้องกันที่สิทธิ์ ไม่ใช่ที่ความลับของรหัส
        $response = $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}");

        $response->assertForbidden();
        $response->assertDontSee('owner@example.com');
        $response->assertDontSee('0899999999');
        $response->assertDontSee('สมชาย ใจดี');
    }

    public function test_the_owner_can_read_their_own_booking(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref);
    }

    public function test_an_invited_companion_can_read_the_booking(): void
    {
        $owner = User::factory()->create();
        $companion = User::factory()->create();
        $booking = $this->makeBooking($owner);

        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($companion, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk();
    }

    public function test_staff_and_admins_can_still_read_any_booking(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner);

        foreach (['admin', 'operator'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
            $staff = User::factory()->create();
            $staff->assignRole($roleName);

            $this->actingAs($staff, 'sanctum')
                ->getJson("/api/v1/bookings/{$booking->booking_ref}")
                ->assertOk();
        }
    }

    public function test_an_unknown_ref_is_still_a_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings/LLK-20260101-9999')
            ->assertNotFound();
    }
}
