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

/**
 * ป้าย "ทีมงานพูดไทย" ในห้องแชท — ให้ลูกค้าต่างชาติรู้ไว้ก่อนว่าสตาฟสื่อสาร
 * ภาษาไทยเป็นหลัก ขึ้นเฉพาะคนที่บอกเราเองว่าเป็นต่างชาติ ไม่ใช่ทุกคนในห้อง
 */
class ChatStaffThaiNoteTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('staff');

        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว', 'slug' => 'thai-note-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 1900, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(6)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /** @param  list<?string>  $nationalities */
    private function booking(User $owner, array $nationalities): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1900,
        ]);
        foreach ($nationalities as $i => $nationality) {
            BookingPassenger::create([
                'booking_id' => $booking->id, 'name' => 'ผู้เดินทาง '.$i, 'nationality' => $nationality,
            ]);
        }

        return $booking;
    }

    private function note(User $viewer): mixed
    {
        return $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/room")
            ->assertOk()
            ->json('data.staff_speak_thai_note');
    }

    public function test_thai_customers_do_not_see_the_note(): void
    {
        $owner = User::factory()->create();
        $this->booking($owner, ['TH', null]);

        $this->assertFalse($this->note($owner));
    }

    public function test_a_booking_with_a_foreign_traveller_shows_the_note_to_its_owner_and_members(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner, ['TH', 'DE']);
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id, 'user_id' => $friend->id, 'status' => BookingMember::STATUS_ACTIVE,
        ]);
        $thaiOwner = User::factory()->create();
        $this->booking($thaiOwner, ['TH']);

        $this->assertTrue($this->note($owner));
        $this->assertTrue($this->note($friend));
        // ใบจองของคนอื่นในห้องไม่เกี่ยว
        $this->assertFalse($this->note($thaiOwner));
    }

    public function test_app_language_or_account_nationality_is_enough(): void
    {
        $english = User::factory()->create(['locale' => 'en']);
        $this->booking($english, ['TH']);
        $foreigner = User::factory()->create(['nationality' => 'FR']);
        $this->booking($foreigner, [null]);

        $this->assertTrue($this->note($english));
        $this->assertTrue($this->note($foreigner));
    }

    public function test_staff_never_see_the_note(): void
    {
        $staff = User::factory()->create(['locale' => 'en']);
        $staff->assignRole('staff');
        $this->schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->assertFalse($this->note($staff));
    }

    public function test_a_thai_owner_whose_foreign_friend_took_their_seat_is_not_foreign(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner, ['TH', 'DE']);
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id, 'user_id' => $friend->id, 'status' => BookingMember::STATUS_ACTIVE,
            'passenger_id' => $booking->passengers()->where('nationality', 'DE')->value('id'),
        ]);

        $this->assertFalse($this->note($owner));
        $this->assertTrue($this->note($friend));
    }

    public function test_a_member_on_their_own_thai_seat_is_not_foreign(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner, ['DE', 'TH']);
        $thai = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id, 'user_id' => $thai->id, 'status' => BookingMember::STATUS_ACTIVE,
            'passenger_id' => $booking->passengers()->where('nationality', 'TH')->value('id'),
        ]);

        $this->assertFalse($this->note($thai));
        $this->assertTrue($this->note($owner));
    }

    public function test_an_owner_who_is_not_travelling_gives_no_signal(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner, ['DE']);
        BookingMember::create([
            'booking_id' => $booking->id, 'user_id' => User::factory()->create()->id, 'status' => BookingMember::STATUS_ACTIVE,
            'passenger_id' => $booking->passengers()->value('id'),
        ]);

        $this->assertFalse($this->note($owner));
    }
}
