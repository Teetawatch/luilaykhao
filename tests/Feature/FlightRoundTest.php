<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\TripActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รอบที่บินไป — จุดนัดพบที่สนามบินแทนจุดขึ้นรถ
 *
 * รอบต่างประเทศไม่มีจุดขึ้นรถและไม่มี GPS รถ ขั้นของการ์ด "วันเดินทาง" ที่ขับ
 * เคลื่อนด้วยตำแหน่งรถจึงไม่มีทางเกิดขึ้น การ์ดเคยค้างอยู่ที่ "จุดรับของคุณ"
 * แล้วกระโดดไป onboard เทสต์ชุดนี้ล็อกไทม์ไลน์สนามบินที่มาแทน
 */
class FlightRoundTest extends TestCase
{
    use RefreshDatabase;

    private function makeFlightSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เทรกกิ้ง ABC เนปาล',
            'slug' => 'flight-round-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Pokhara',
            'destination_type' => 'international',
            'country_code' => 'NP',
            'difficulty' => 'hard',
            'duration_days' => 10,
            'max_participants' => 12,
            'price_per_person' => 55000,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'departs_at' => now('Asia/Bangkok')->addHours(6)->format('Y-m-d H:i:s'),
            'return_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 1,
            'status' => 'open',
            'transport_type' => 'flight',
            'meeting_point' => 'สนามบินสุวรรณภูมิ ชั้น 4 เคาน์เตอร์ D',
            'meeting_time' => now('Asia/Bangkok')->addHours(3)->format('H:i'),
            'baggage_allowance' => 'โหลด 20 กก. + ถือขึ้นเครื่อง 7 กก.',
            'flights' => [
                [
                    'direction' => 'outbound',
                    'airline' => 'Thai Airways',
                    'flight_no' => 'TG319',
                    'from' => 'BKK',
                    'to' => 'KTM',
                ],
                [
                    'direction' => 'return',
                    'airline' => 'Thai Airways',
                    'flight_no' => 'TG320',
                    'from' => 'KTM',
                    'to' => 'BKK',
                ],
            ],
        ], $overrides));
    }

    private function makeBooking(TripSchedule $schedule, User $user): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 55000,
            'paid_amount' => 55000,
        ]);
    }

    // ── ข้อมูลเที่ยวบิน ──────────────────────────────────────────────────

    public function test_the_schedule_payload_carries_the_flight_plan(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeFlightSchedule();
        $booking = $this->makeBooking($schedule, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk();

        $response->assertJsonPath(
            'data.schedule.flight_plan.meeting_point',
            'สนามบินสุวรรณภูมิ ชั้น 4 เคาน์เตอร์ D',
        );
        $response->assertJsonPath('data.schedule.flight_plan.legs.outbound.0.flight_no', 'TG319');
        $response->assertJsonPath('data.schedule.flight_plan.legs.return.0.flight_no', 'TG320');
        $response->assertJsonPath('data.schedule.allows_seat_selection', false);
    }

    /** รอบรถตู้ไม่ควรมีก้อนแผนการบินติดมาให้ client ต้องเช็คเปล่า ๆ */
    public function test_a_van_round_has_no_flight_plan(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeFlightSchedule(['transport_type' => 'van']);
        $booking = $this->makeBooking($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonMissingPath('data.schedule.flight_plan');
    }

    /**
     * ไฟลต์ดึก: เครื่องออก 00:30 ของวันเดินทาง แต่นัดเจอกัน 21:30 ของวันก่อนหน้า
     * ถ้าเอา meeting_time ไปต่อกับ departure_date ตรง ๆ จะได้เวลานัดพบหลังเครื่องออก
     */
    public function test_a_red_eye_flight_meets_the_night_before(): void
    {
        $departure = now('Asia/Bangkok')->addDays(20)->startOfDay()->addMinutes(30);
        $schedule = $this->makeFlightSchedule([
            'departure_date' => $departure->toDateString(),
            'departs_at' => $departure->format('Y-m-d H:i:s'),
            'meeting_time' => '21:30',
        ]);

        $meetingAt = $schedule->meetingAt();

        $this->assertNotNull($meetingAt);
        $this->assertSame(
            $departure->copy()->subDay()->format('Y-m-d 21:30'),
            $meetingAt->format('Y-m-d H:i'),
        );
        $this->assertTrue($meetingAt->lt($schedule->departs_at));
    }

    public function test_the_admin_can_fill_the_flight_plan_in_later(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeFlightSchedule([
            'meeting_point' => null,
            'meeting_time' => null,
            'flights' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}", [
                'meeting_point' => 'สนามบินดอนเมือง อาคาร 1 เคาน์เตอร์ 8',
                'meeting_time' => '04:00',
                'baggage_allowance' => 'โหลด 15 กก.',
                'flights' => [
                    ['direction' => 'outbound', 'airline' => 'AirAsia', 'flight_no' => 'FD3510'],
                ],
            ])
            ->assertOk();

        $schedule->refresh();

        $this->assertSame('สนามบินดอนเมือง อาคาร 1 เคาน์เตอร์ 8', $schedule->meeting_point);
        $this->assertSame('04:00', $schedule->meeting_time);
        $this->assertSame('FD3510', $schedule->flightLegs()['outbound'][0]['flight_no']);
    }

    public function test_a_bad_meeting_time_is_rejected(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $schedule = $this->makeFlightSchedule();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}", [
                'meeting_time' => 'ตีสี่',
            ])
            ->assertStatus(422);
    }

    // ── การ์ด "วันเดินทาง" ───────────────────────────────────────────────

    public function test_the_card_counts_down_to_the_meeting_time_not_the_van(): void
    {
        $user = User::factory()->create();
        // นัดพบอีก 3 ชม. (อยู่ในหน้าต่าง preparing 6 ชม.) เครื่องออกอีก 6 ชม.
        $schedule = $this->makeFlightSchedule();
        $booking = $this->makeBooking($schedule, $user);
        $booking->setRelation('schedule', $schedule);

        $state = app(TripActivityService::class)->stateFor($booking);

        $this->assertNotNull($state);
        $this->assertSame('preparing', $state['stage']);
        // ไม่มีคำว่า "รถ" อยู่ในการ์ดของรอบที่บินไป
        $this->assertStringNotContainsString('รถ', $state['headline']);
        $this->assertStringNotContainsString('รถ', $state['detail']);
        $this->assertStringContainsString('เจอกัน', $state['headline']);
        $this->assertStringContainsString('สุวรรณภูมิ', $state['detail']);
        // ป้ายพาหนะกลายเป็นเที่ยวบิน และจุดรับกลายเป็นจุดนัดพบ
        $this->assertSame('Thai Airways TG319', $state['vehicle_label']);
        $this->assertStringContainsString('สุวรรณภูมิ', $state['pickup_name']);
        // นับถอยหลังไปหาเวลานัดพบ (~180 นาที) ไม่ใช่เวลาเครื่องออก
        $this->assertGreaterThan(150, $state['eta_minutes']);
        $this->assertLessThan(200, $state['eta_minutes']);
    }

    public function test_the_card_switches_to_meetup_when_the_time_is_close(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeFlightSchedule([
            'departs_at' => now('Asia/Bangkok')->addHours(3)->format('Y-m-d H:i:s'),
            'meeting_time' => now('Asia/Bangkok')->addMinutes(15)->format('H:i'),
        ]);
        $booking = $this->makeBooking($schedule, $user);
        $booking->setRelation('schedule', $schedule);

        $state = app(TripActivityService::class)->stateFor($booking);

        $this->assertSame('meetup', $state['stage']);
        $this->assertStringContainsString('เจอทีมงาน', $state['headline']);
        $this->assertGreaterThan(0.5, $state['progress']);
    }

    public function test_the_card_switches_to_boarding_near_departure(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeFlightSchedule([
            'departs_at' => now('Asia/Bangkok')->addMinutes(40)->format('Y-m-d H:i:s'),
            'meeting_time' => now('Asia/Bangkok')->subHours(2)->format('H:i'),
        ]);
        $booking = $this->makeBooking($schedule, $user);
        $booking->setRelation('schedule', $schedule);

        $state = app(TripActivityService::class)->stateFor($booking);

        $this->assertSame('boarding', $state['stage']);
        $this->assertStringContainsString('เครื่องออก', $state['headline']);
        $this->assertStringContainsString('ประตูขึ้นเครื่อง', $state['detail']);
    }

    public function test_a_checked_in_traveller_is_onboard(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeFlightSchedule();
        $booking = $this->makeBooking($schedule, $user);
        $booking->update(['checked_in' => true, 'checked_in_at' => now()]);
        $booking->setRelation('schedule', $schedule);

        $state = app(TripActivityService::class)->stateFor($booking);

        $this->assertSame('onboard', $state['stage']);
        $this->assertStringContainsString('เช็คอินกับทีมงาน', $state['headline']);
        $this->assertSame(1.0, $state['progress']);
    }

    /** ยังไม่ได้กรอกเวลานัดพบ ก็ยังต้องบอกอะไรได้จากเวลาเครื่องออก ไม่ใช่เงียบ */
    public function test_the_card_still_works_before_the_flight_plan_is_filled_in(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeFlightSchedule([
            'meeting_point' => null,
            'meeting_time' => null,
            'flights' => null,
            'departs_at' => now('Asia/Bangkok')->addHours(4)->format('Y-m-d H:i:s'),
        ]);
        $booking = $this->makeBooking($schedule, $user);
        $booking->setRelation('schedule', $schedule);

        $state = app(TripActivityService::class)->stateFor($booking);

        $this->assertNotNull($state);
        $this->assertSame('preparing', $state['stage']);
        $this->assertStringContainsString('จุดนัดพบที่สนามบิน', $state['detail']);
        $this->assertNull($state['vehicle_label']);
    }

    /** ข้อมูลจุดนัดพบต้องไปถึงหน้าติดตาม ไม่งั้นการ์ดในแอปจะขึ้นว่า "ยังไม่มีสัญญาณรถ" */
    public function test_the_tracking_payload_tells_the_app_this_is_a_flight(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeFlightSchedule();
        $booking = $this->makeBooking($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/tracking")
            ->assertOk()
            ->assertJsonPath('data.transport_type', 'flight')
            ->assertJsonPath('data.flight_label', 'Thai Airways TG319')
            ->assertJsonPath(
                'data.meeting_point',
                'สนามบินสุวรรณภูมิ ชั้น 4 เคาน์เตอร์ D',
            );
    }
}
