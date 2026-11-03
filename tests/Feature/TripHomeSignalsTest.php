<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Promotion;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ข้อมูลที่การ์ดบนหน้าแรกของแอปใช้: "จองแล้ว N คนในสัปดาห์นี้" และทริปที่
 * คูปองใช้ได้ (แอปเก็บคูปองไว้เสนอตอนจองเฉพาะทริปที่โค้ดผ่านจริง)
 */
class TripHomeSignalsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title): Trip
    {
        return Trip::create([
            'title' => $title,
            'slug' => str()->slug($title).'-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
    }

    private function schedule(Trip $trip): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(14)->toDateString(),
            'return_date' => now()->addDays(15)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(TripSchedule $schedule, int $people, string $status, $createdAt): void
    {
        $booking = Booking::create([
            // generateRef นับตามวันที่สร้าง — ใบที่ย้อนวันไว้จะทำให้เลขชนกัน
            'booking_ref' => 'LLK-TEST-'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 1500 * $people,
        ]);
        $booking->forceFill(['created_at' => $createdAt])->saveQuietly();
        for ($i = 0; $i < $people; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'Mr.',
                'name' => 'Pax '.$i,
                'phone' => '08100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function test_booked_this_week_counts_confirmed_travellers_from_the_last_seven_days(): void
    {
        $trip = $this->makeTrip('Weekly Trek');
        $schedule = $this->schedule($trip);
        $this->book($schedule, 3, 'confirmed', now()->subDays(2));
        $this->book($schedule, 2, 'completed', now()->subDays(6));
        $this->book($schedule, 4, 'pending', now()->subDay());      // ยังไม่จ่าย
        $this->book($schedule, 5, 'cancelled', now()->subDay());    // ยกเลิก
        $this->book($schedule, 6, 'confirmed', now()->subDays(8));  // เก่ากว่า 7 วัน

        $other = $this->makeTrip('Other Trek');
        $this->book($this->schedule($other), 7, 'confirmed', now()->subDay());

        $rows = collect($this->getJson('/api/v1/trips')->assertOk()->json('data'));

        $this->assertSame(5, $rows->firstWhere('slug', $trip->slug)['booked_this_week']);
        $this->assertSame(7, $rows->firstWhere('slug', $other->slug)['booked_this_week']);
    }

    public function test_booked_this_week_is_zero_for_a_quiet_trip(): void
    {
        $trip = $this->makeTrip('Quiet Trek');
        $this->schedule($trip);

        $row = collect($this->getJson('/api/v1/trips')->json('data'))->firstWhere('slug', $trip->slug);

        $this->assertSame(0, $row['booked_this_week']);
    }

    public function test_active_promotions_say_which_trips_they_apply_to(): void
    {
        $trip = $this->makeTrip('Promo Trek');
        Promotion::create([
            'code' => 'ONLYONE',
            'name' => 'Only one trip',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
            'trip_ids' => [$trip->id],
        ]);
        Promotion::create([
            'code' => 'EVERYWHERE',
            'name' => 'All trips',
            'type' => 'fixed',
            'value' => 100,
            'is_active' => true,
        ]);
        Promotion::create([
            'code' => 'EMPTYLIST',
            'name' => 'Empty list means all',
            'type' => 'fixed',
            'value' => 50,
            'is_active' => true,
            'trip_ids' => [],
        ]);

        $rows = collect($this->getJson('/api/v1/promotions/active')->assertOk()->json('data'))
            ->keyBy('code');

        $this->assertSame([$trip->id], $rows['ONLYONE']['trip_ids']);
        $this->assertNull($rows['EVERYWHERE']['trip_ids']);
        $this->assertNull($rows['EMPTYLIST']['trip_ids']);
    }
}
