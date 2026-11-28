<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ชื่อ/นามสกุลแยกช่อง ในทางเข้าที่เหลือ: โปรไฟล์ เอกสารเดินทาง (ชื่ออังกฤษตาม
 * พาสปอร์ตหลังจอง) และพรีฟิลของหน้ารับที่นั่งต่อ
 */
class NamePartsEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_profile_saves_first_and_last_name_and_sends_them_back(): void
    {
        $user = User::factory()->create(['name' => 'Pond']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.first_name', 'Pond')
            ->assertJsonPath('data.last_name', '');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', ['first_name' => ' สมชาย ', 'last_name' => 'ณ  อยุธยา'])
            ->assertOk()
            ->assertJsonPath('data.name', 'สมชาย ณ อยุธยา')
            ->assertJsonPath('data.first_name', 'สมชาย')
            ->assertJsonPath('data.last_name', 'ณ อยุธยา');

        $this->assertSame('สมชาย ณ อยุธยา', $user->fresh()->name);
    }

    public function test_profile_requires_a_last_name_with_a_first_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', ['first_name' => 'สมชาย'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('last_name');
    }

    public function test_old_app_editing_only_the_full_name_still_works(): void
    {
        $user = User::factory()->create(['first_name' => 'สมชาย', 'last_name' => 'ใจดี']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', ['name' => 'มานะ ขยันดี'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'มานะ')
            ->assertJsonPath('data.last_name', 'ขยันดี');
        $this->assertNull($user->fresh()->first_name);
    }

    public function test_travel_documents_take_the_passport_name_in_two_boxes(): void
    {
        $owner = User::factory()->create();
        $trip = Trip::create([
            'title' => 'หลวงพระบาง',
            'slug' => 'laos-'.uniqid(),
            'type' => 'trekking',
            'location' => 'ลาว',
            'difficulty' => 'easy',
            'duration_days' => 3,
            'max_participants' => 10,
            'price_per_person' => 9000,
            'status' => 'active',
            'destination_type' => 'international',
            'country_code' => 'LA',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-08-10',
            'return_date' => '2026-08-12',
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 9000,
        ]);
        $passenger = BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nationality' => 'TH',
            'phone' => '0812345678',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [[
                    'id' => $passenger->id,
                    'first_name_en' => 'Mary Jane',
                    'last_name_en' => 'van der Berg',
                    'passport_no' => 'AA1234567',
                    'passport_expires_at' => '2030-01-01',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.passengers.0.name_en', 'MARY JANE VAN DER BERG')
            ->assertJsonPath('data.passengers.0.first_name_en', 'MARY JANE')
            ->assertJsonPath('data.passengers.0.last_name_en', 'VAN DER BERG');

        $fresh = $passenger->fresh();
        $this->assertSame('MARY JANE', $fresh->first_name_en);
        $this->assertSame('VAN DER BERG', $fresh->last_name_en);
    }
}
