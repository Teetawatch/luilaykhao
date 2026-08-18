<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SosAlert;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Support\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * วีซ่า เบอร์ฉุกเฉิน เขตเวลา และนโยบายยกเลิกของทริปต่างประเทศ
 *
 * สามเรื่องนี้เคยไม่มีที่อยู่ในระบบเลย ทีมงานจึงต้องตอบซ้ำในแชททุกคน และเรื่องที่
 * แพงที่สุดคือนโยบายยกเลิก — ตั๋วเครื่องบินคืนเงินไม่ได้ ถ้าใช้บันไดคืนเงินของ
 * ทริปในประเทศ (คืน 80% เมื่อยกเลิกก่อน 7 วัน) เราจ่ายค่าตั๋วเองทั้งก้อน
 */
class InternationalTravelInfoTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'เทรกกิ้ง ABC เนปาล',
            'slug' => 'intl-info-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Pokhara',
            'destination_type' => 'international',
            'country_code' => 'NP',
            'difficulty' => 'hard',
            'duration_days' => 10,
            'max_participants' => 12,
            'price_per_person' => 55000,
            'status' => 'active',
        ], $overrides));
    }

    private function makeSchedule(Trip $trip, int $daysUntilDeparture): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysUntilDeparture)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysUntilDeparture + 10)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 1,
            'status' => 'open',
            'transport_type' => 'flight',
        ]);
    }

    private function makeBooking(TripSchedule $schedule, User $user, float $paid = 55000): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'payment_type' => 'full',
            'total_amount' => $paid,
            'paid_amount' => $paid,
        ]);
        $booking->setRelation('schedule', $schedule);

        return $booking;
    }

    // ── ทะเบียนประเทศ ────────────────────────────────────────────────────

    public function test_the_registry_answers_visa_and_emergency_for_a_country(): void
    {
        $visa = Countries::visa('NP');

        $this->assertSame(Countries::VISA_ON_ARRIVAL, $visa['status']);
        $this->assertSame('ขอวีซ่าที่ปลายทาง', $visa['label']);
        // ข้อความกำกับต้องติดมาทุกครั้ง ไม่ใช่ให้แต่ละหน้าจอเลือกใส่เอง
        $this->assertNotEmpty($visa['disclaimer']);
        $this->assertNotEmpty($visa['checked_at']);

        $this->assertSame(['ตำรวจ' => '100', 'รถพยาบาล' => '102'], Countries::emergency('NP'));
    }

    public function test_an_unknown_country_answers_nothing_rather_than_guessing(): void
    {
        $this->assertNull(Countries::visa('ZZ'));
        $this->assertSame([], Countries::emergency('ZZ'));
        $this->assertSame([], Countries::emergency(null));
    }

    /** ทุกประเทศในทะเบียนต้องกรอกครบ ไม่งั้นหน้าจอจะขึ้นช่องว่าง */
    public function test_every_country_in_the_registry_is_complete(): void
    {
        foreach (Countries::options() as $option) {
            $code = $option['code'];
            $this->assertNotEmpty($option['name'], "$code ไม่มีชื่อไทย");
            $this->assertNotEmpty($option['flag'], "$code ไม่มีธง");
            $this->assertNotEmpty($option['timezone'], "$code ไม่มีเขตเวลา");
            $this->assertNotEmpty($option['visa']['label'], "$code ไม่มีหมวดวีซ่า");
            $this->assertNotEmpty($option['visa']['note'], "$code ไม่มีคำอธิบายวีซ่า");
            $this->assertNotEmpty($option['emergency'], "$code ไม่มีเบอร์ฉุกเฉิน");
        }
    }

    public function test_the_trip_payload_carries_visa_and_emergency_numbers(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, 60);

        $response = $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk();

        $response->assertJsonPath('data.visa.status', Countries::VISA_ON_ARRIVAL);
        $response->assertJsonPath('data.emergency_numbers.ตำรวจ', '100');
    }

    public function test_a_domestic_trip_carries_neither(): void
    {
        $trip = $this->makeTrip([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);
        $this->makeSchedule($trip, 60);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.visa', null)
            ->assertJsonPath('data.emergency_numbers', []);
    }

    // ── เขตเวลาปลายทาง ───────────────────────────────────────────────────

    public function test_the_trip_reports_how_far_the_destination_clock_is_from_thai_time(): void
    {
        $nepal = $this->makeTrip();
        // เนปาลช้ากว่าไทย 1 ชม. 15 นาที (UTC+5:45 เทียบ UTC+7)
        $this->assertSame(-75, $nepal->destinationOffsetMinutes());

        $japan = $this->makeTrip(['country_code' => 'JP']);
        $this->assertSame(120, $japan->destinationOffsetMinutes());

        // ลาวตรงกับไทย — แอปใช้ค่านี้ซ่อนป้ายนาฬิกาทิ้ง
        $laos = $this->makeTrip(['country_code' => 'LA']);
        $this->assertSame(0, $laos->destinationOffsetMinutes());

        $domestic = $this->makeTrip([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);
        $this->assertNull($domestic->destinationOffsetMinutes());
    }

    /** เขตเวลาที่แอดมินกรอกผิดไม่ควรทำให้หน้าทริปพัง */
    public function test_a_bad_admin_timezone_degrades_quietly(): void
    {
        $trip = $this->makeTrip(['timezone' => 'Mars/Olympus_Mons']);

        $this->assertNull($trip->destinationOffsetMinutes());
    }

    // ── นโยบายยกเลิก ─────────────────────────────────────────────────────

    public function test_an_international_trip_shows_its_own_cancellation_policy(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, 60);

        $response = $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk();

        $this->assertSame(
            config('payment.cancellation_policy_international.free_change_days'),
            $response->json('data.cancellation_policy.free_change_days'),
        );
        // ต้องบอกให้ชัดว่าตั๋วเครื่องบินคืนไม่ได้ ไม่ใช่ปล่อยให้ไปรู้ตอนขอยกเลิก
        $this->assertStringContainsString(
            'ตั๋วเครื่องบิน',
            $response->json('data.cancellation_policy.note'),
        );
    }

    public function test_a_domestic_trip_keeps_the_original_policy(): void
    {
        $trip = $this->makeTrip([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);
        $this->makeSchedule($trip, 60);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath(
                'data.cancellation_policy.free_change_days',
                config('payment.cancellation_policy.free_change_days'),
            );
    }

    /**
     * นี่คือข้อที่ราคาแพงที่สุด: ยกเลิกทริปเนปาล 10 วันก่อนบิน บันไดของทริปใน
     * ประเทศจะคืน 80% ของห้าหมื่น แต่ค่าตั๋วจ่ายออกไปแล้วและเอาคืนไม่ได้
     */
    public function test_an_international_cancellation_does_not_refund_the_air_ticket(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 10);
        $booking = $this->makeBooking($schedule, $user);

        $result = app(BookingService::class)->calculateRefundAmount($booking);

        $this->assertSame(0, $result['refund_percent']);
        $this->assertSame(0.0, $result['refund_amount']);
        $this->assertStringContainsString('ตั๋วเครื่องบิน', $result['policy_note']);
    }

    public function test_cancelling_before_the_ticket_is_issued_refunds_in_full(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 90);
        $booking = $this->makeBooking($schedule, $user);

        $result = app(BookingService::class)->calculateRefundAmount($booking);

        $this->assertSame(100, $result['refund_percent']);
        $this->assertSame(55000.0, $result['refund_amount']);
    }

    public function test_the_middle_tier_refunds_part_of_the_trip(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 50);
        $booking = $this->makeBooking($schedule, $user);

        $result = app(BookingService::class)->calculateRefundAmount($booking);

        $this->assertSame(
            config('payment.cancellation_policy_international.partial_refund_percent'),
            $result['refund_percent'],
        );
        $this->assertSame(27500.0, $result['refund_amount']);
    }

    /** บันไดของทริปในประเทศต้องไม่ขยับแม้แต่นิดเดียว */
    public function test_the_domestic_refund_ladder_is_untouched(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);

        $far = $this->makeBooking($this->makeSchedule($trip, 10), $user, 2500);
        $this->assertSame(80, app(BookingService::class)->calculateRefundPercent($far));

        $mid = $this->makeBooking($this->makeSchedule($trip, 4), $user, 2500);
        $this->assertSame(50, app(BookingService::class)->calculateRefundPercent($mid));

        $near = $this->makeBooking($this->makeSchedule($trip, 1), $user, 2500);
        $this->assertSame(0, app(BookingService::class)->calculateRefundPercent($near));
    }

    // ── เบอร์ฉุกเฉินบนสัญญาณ SOS ─────────────────────────────────────────

    public function test_an_sos_from_abroad_carries_the_local_emergency_numbers(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        // SOS ใช้ได้เฉพาะช่วงทริป — ตั้งวันเดินทางเป็นวันนี้
        $schedule = $this->makeSchedule($trip, 0);
        $this->makeBooking($schedule, $user);

        $alert = SosAlert::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'contact_phone' => '0810000000',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sos/active')
            ->assertOk();

        $this->assertSame($alert->id, $response->json('data.0.id'));
        $response->assertJsonPath('data.0.emergency_numbers.รถพยาบาล', '102');
    }

    public function test_an_sos_from_a_domestic_trip_has_no_extra_numbers(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);
        $schedule = $this->makeSchedule($trip, 0);
        $this->makeBooking($schedule, $user);

        SosAlert::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'contact_phone' => '0810000000',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sos/active')
            ->assertOk()
            ->assertJsonPath('data.0.emergency_numbers', []);
    }
}
