<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingRefTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 8, 'price_per_person' => 1000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-05-08', 'return_date' => '2026-05-08',
            'total_seats' => 8, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function makeBooking(string $ref): Booking
    {
        return Booking::create([
            'booking_ref' => $ref,
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->makeSchedule()->id,
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
        ]);
    }

    public function test_sequence_starts_at_one_and_increments_within_the_day(): void
    {
        $today = now()->format('Ymd');

        $this->assertSame("LLK-{$today}-0001", Booking::generateRef());

        $this->makeBooking(Booking::generateRef());
        $this->assertSame("LLK-{$today}-0002", Booking::generateRef());

        $this->makeBooking(Booking::generateRef());
        $this->assertSame("LLK-{$today}-0003", Booking::generateRef());
    }

    public function test_sequence_resets_for_a_new_day(): void
    {
        $this->makeBooking('LLK-20260101-0007')->forceFill(['created_at' => '2026-01-01 10:00:00'])->save();

        $today = now()->format('Ymd');
        $this->assertSame("LLK-{$today}-0001", Booking::generateRef());
    }

    /**
     * เลขที่จองผิดรูปแบบต้องไม่ทำให้ทั้งวันออกเลขไม่ได้ — hex ท้าย ๆ อย่าง "9e18"
     * เคยถูก PHP ตีเป็น 9.2E+18 แล้วแคสต์เป็น int ไม่ได้จนโยน ErrorException
     */
    public function test_a_malformed_ref_is_ignored_instead_of_crashing(): void
    {
        $today = now()->format('Ymd');

        $this->makeBooking("LLK-{$today}-0001");
        $this->makeBooking("LLK-{$today}-0002-9e18");

        // เลขที่ผิดรูปแบบไม่นับเข้าลำดับ — ตัวที่ตรงรูปแบบสูงสุดคือ 0001
        $this->assertSame("LLK-{$today}-0002", Booking::generateRef());
    }

    public function test_sequence_follows_the_highest_number_not_the_newest_row(): void
    {
        $today = now()->format('Ymd');

        $this->makeBooking("LLK-{$today}-0009");
        $this->makeBooking("LLK-{$today}-0004");

        $this->assertSame("LLK-{$today}-0010", Booking::generateRef());
    }
}
