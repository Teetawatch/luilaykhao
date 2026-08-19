<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * รอบที่บินไปไม่มีรถวิ่งรับ — ไม่มีจุดขึ้นรถให้เลือกและไม่มีอะไรให้ปักหมุด
 * นัดเจอกันที่สนามบินแทน (ดู meeting_point/meeting_time ของรอบ)
 *
 * "บิน" เป็นคุณสมบัติของรอบ ไม่ใช่ของทริป — ทริปในประเทศก็บินได้ ข้อยกเว้นเดิม
 * ที่ผูกกับ trip.is_international จึงไม่ครอบคลุมรอบพวกนี้
 */
class FlightSchedulePickupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'บินไปเชียงราย เดินภูชี้ฟ้า',
            'slug' => 'phu-chi-fa-fly',
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'easy',
            'duration_days' => 3,
            'max_participants' => 16,
            'price_per_person' => 8900,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, string $transportType): TripSchedule
    {
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDays(2)->toDateString(),
            'total_seats' => 16,
            'booked_seats' => 0,
            'transport_type' => $transportType,
            'status' => 'open',
            'meeting_point' => $transportType === 'flight'
                ? 'สนามบินดอนเมือง อาคาร 1 เคาน์เตอร์ 5'
                : null,
            'meeting_time' => $transportType === 'flight' ? '04:30' : null,
        ]);

        // จุดรับที่ตั้งค้างไว้ (เช่น รอบนี้เคยเป็นรอบรถตู้มาก่อน) ต้องไม่ทำให้
        // รอบที่บินไปกลับมาบังคับเลือกจุดขึ้นรถอีก
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 9900,
        ]);

        return $schedule;
    }

    private function passengerPayload(): array
    {
        return [[
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'id_card' => '1234567890123',
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ]];
    }

    public function test_booking_a_flight_round_without_any_pickup_is_accepted(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 'flight');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
            ])
            ->assertCreated();

        $booking = Booking::firstOrFail();
        $this->assertNull($booking->pickup_point_id);
        $this->assertNull($booking->pickup_region);
        // ราคาเป็นราคาของรอบ ไม่ใช่ราคาโซนของจุดรับที่ค้างอยู่
        $this->assertEquals(8900, (float) $booking->total_amount);
    }

    public function test_the_same_trip_still_requires_a_pickup_on_its_van_round(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, 'flight');
        $vanRound = $this->makeSchedule($trip, 'van');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $vanRound->id,
                'passengers' => $this->passengerPayload(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_point_id');
    }

    /**
     * ไคลเอนต์รุ่นเก่า (หรือร่างการจองที่ค้างจากรอบรถตู้) อาจส่งจุดขึ้นรถติดมา —
     * ต้องไม่ถูกบันทึกและต้องไม่ดันราคาขึ้นเป็นราคาโซน
     */
    public function test_a_pickup_point_sent_for_a_flight_round_is_ignored(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 'flight');
        $point = SchedulePickupPoint::where('schedule_id', $schedule->id)->firstOrFail();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'pickup_point_id' => $point->id,
                'pickup_region' => 'bangkok',
                'passengers' => [
                    ['pickup_point_id' => $point->id] + $this->passengerPayload()[0],
                ],
            ])
            ->assertCreated();

        $booking = Booking::firstOrFail();
        $this->assertNull($booking->pickup_point_id);
        $this->assertNull($booking->pickup_region);
        $this->assertNull($booking->passengers()->first()->pickup_point_id);
        $this->assertEquals(8900, (float) $booking->total_amount);
    }

    public function test_a_custom_pin_sent_for_a_flight_round_is_ignored(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 'flight');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
                'custom_pickup_label' => 'หน้าหมู่บ้าน',
                'custom_pickup_lat' => 13.7563,
                'custom_pickup_lng' => 100.5018,
            ])
            ->assertCreated();

        $booking = Booking::firstOrFail();
        $this->assertNull($booking->custom_pickup_label);
        $this->assertNull($booking->custom_pickup_status);
        $this->assertEquals(8900, (float) $booking->total_amount);
    }

    /**
     * แอปอ่าน transport_type + flight_plan จาก payload ของรอบเพื่อสลับขั้นตอน
     * "จุดขึ้นรถ" เป็น "จุดนัดพบ" — ถ้าสองคีย์นี้หาย แอปจะกลับไปขอให้ปักหมุด
     */
    public function test_schedule_payload_carries_the_flight_meeting_details(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, 'flight');

        $response = $this->getJson("/api/v1/trips/{$trip->slug}/schedules")->assertOk();

        $this->assertSame('flight', $response->json('data.0.transport_type'));
        $this->assertSame(
            'สนามบินดอนเมือง อาคาร 1 เคาน์เตอร์ 5',
            $response->json('data.0.flight_plan.meeting_point')
        );
        $this->assertSame('04:30', $response->json('data.0.flight_plan.meeting_time'));
    }
}
