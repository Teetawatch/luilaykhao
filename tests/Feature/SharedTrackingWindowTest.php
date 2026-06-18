<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ลิงก์ติดตามรถสาธารณะ (share token) ต้องเปิดให้ติดตามตั้งแต่ "วันที่รถออกจริง"
 * (departs_at) ซึ่งอาจเป็นคืนก่อนวันทริป ไม่ใช่รอจนถึงวันทริปเท่านั้น
 */
class SharedTrackingWindowTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $scheduleOverrides): Booking
    {
        $trip = Trip::create([
            'title' => 'Track Trip', 'slug' => 'track-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 1500, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'total_seats' => 10, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
        ], $scheduleOverrides));

        $booking = Booking::create([
            'booking_ref' => 'LLK-TRK-'.strtoupper(uniqid()),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'payment_type' => 'full',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
        $booking->ensureShareToken();

        return $booking;
    }

    public function test_trackable_the_night_vehicle_departs_before_trip_day(): void
    {
        // วันทริปพรุ่งนี้ แต่รถออกคืนนี้ (departs_at วันนี้) → ต้องติดตามได้แล้ววันนี้
        $booking = $this->makeBooking([
            'departure_date' => now()->addDay()->toDateString(),
            'departs_at' => now()->setTime(22, 30)->format('Y-m-d H:i:s'),
            'return_date' => now()->addDay()->toDateString(),
        ]);

        $res = $this->getJson('/api/v1/track/'.$booking->share_token)->assertOk();

        $this->assertNotSame('จะติดตามรถได้ในวันออกเดินทาง', $res->json('data.message'));
    }

    public function test_not_trackable_before_departs_day(): void
    {
        // รถออกพรุ่งนี้ → วันนี้ยังติดตามไม่ได้
        $booking = $this->makeBooking([
            'departure_date' => now()->addDays(2)->toDateString(),
            'departs_at' => now()->addDay()->setTime(22, 0)->format('Y-m-d H:i:s'),
            'return_date' => now()->addDays(2)->toDateString(),
        ]);

        $res = $this->getJson('/api/v1/track/'.$booking->share_token)->assertOk();

        $this->assertSame('จะติดตามรถได้ในวันออกเดินทาง', $res->json('data.message'));
    }

    public function test_not_trackable_after_trip_ends(): void
    {
        $booking = $this->makeBooking([
            'departure_date' => now()->subDays(3)->toDateString(),
            'departs_at' => now()->subDays(3)->setTime(6, 0)->format('Y-m-d H:i:s'),
            'return_date' => now()->subDays(2)->toDateString(),
        ]);

        $res = $this->getJson('/api/v1/track/'.$booking->share_token)->assertOk();

        $this->assertSame('ทริปนี้สิ้นสุดแล้ว', $res->json('data.message'));
    }
}
