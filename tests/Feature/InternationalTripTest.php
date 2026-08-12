<?php

namespace Tests\Feature;

use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ทริปต่างประเทศ — ใบอนุญาต 11/13855 นำเที่ยวได้ทั้งในและต่างประเทศ
 *
 * สิ่งที่ต่างจากทริปในประเทศมีสามเรื่อง: บังคับเก็บเอกสารเดินทาง, ไม่บังคับ
 * จุดขึ้นรถ, และรับเบอร์โทรรูปแบบสากลของคนที่ไม่ได้ถือสัญชาติไทย
 */
class InternationalTripTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $tripOverrides = []): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'เทรกกิ้ง ABC เนปาล',
            'slug' => 'abc-nepal-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Pokhara',
            'destination_type' => 'international',
            'country_code' => 'NP',
            'difficulty' => 'hard',
            'duration_days' => 10,
            'max_participants' => 12,
            'price_per_person' => 55000,
            'status' => 'active',
        ], $tripOverrides));

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonths(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonths(3)->addDays(10)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /** ผู้โดยสารไทยที่กรอกครบทุกช่องรวมเอกสารเดินทาง */
    private function passenger(array $overrides = []): array
    {
        return array_merge([
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'id_card' => '1234567890123',
            'name_en' => 'SOMCHAI JAIDEE',
            'passport_no' => 'AA1234567',
            'passport_expires_at' => now('Asia/Bangkok')->addYears(3)->toDateString(),
            'phone' => '0810000000',
            'blood_group' => 'A',
            'halal_food' => false,
            'emergency_contact' => 'สมหญิง',
            'emergency_phone' => '0820000000',
        ], $overrides);
    }

    public function test_international_booking_stores_travel_documents(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger()],
            ])
            ->assertCreated();

        $passenger = $schedule->bookings()->first()->passengers()->first();

        $this->assertSame('SOMCHAI JAIDEE', $passenger->name_en);
        $this->assertSame('AA1234567', $passenger->passport_no);
        $this->assertSame('TH', $passenger->nationality);
        $this->assertNotNull($passenger->passport_expires_at);
    }

    public function test_passport_number_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger()],
            ])
            ->assertCreated();

        $raw = DB::table('booking_passengers')->value('passport_no');

        $this->assertNotSame('AA1234567', $raw);
        $this->assertStringNotContainsString('AA1234567', (string) $raw);
    }

    public function test_international_booking_requires_travel_documents(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger([
                    'name_en' => null,
                    'passport_no' => null,
                    'passport_expires_at' => null,
                ])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'passengers.0.name_en',
                'passengers.0.passport_no',
                'passengers.0.passport_expires_at',
            ]);
    }

    public function test_passport_must_be_valid_six_months_past_departure(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        // ยังไม่หมดอายุตอนจอง แต่หมดก่อนครบ 6 เดือนหลังวันเดินทาง
        $tooSoon = $schedule->departure_date->copy()->addMonths(5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger([
                    'passport_expires_at' => $tooSoon->toDateString(),
                ])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['passengers.0.passport_expires_at']);
    }

    public function test_exactly_six_months_past_departure_is_accepted(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger([
                    'passport_expires_at' => $schedule->departure_date
                        ->copy()->addMonths(6)->toDateString(),
                ])],
            ])
            ->assertCreated();
    }

    public function test_non_thai_traveller_uses_passport_instead_of_id_card(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger([
                    'nationality' => 'JP',
                    'id_card' => null,
                    'phone' => '+81 90 1234 5678',
                    'emergency_phone' => '+81 90 8765 4321',
                ])],
            ])
            ->assertCreated();

        $passenger = $schedule->bookings()->first()->passengers()->first();

        $this->assertSame('JP', $passenger->nationality);
        $this->assertNull($passenger->id_card);
    }

    public function test_thai_traveller_still_needs_a_thai_id_and_ten_digit_phone(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger([
                    'id_card' => null,
                    'phone' => '+81 90 1234 5678',
                ])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'passengers.0.id_card',
                'passengers.0.phone',
            ]);
    }

    public function test_pickup_point_is_not_required_on_an_international_trip(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        // รอบมีจุดขึ้นรถตั้งไว้ แต่ทริปบินออกนอกประเทศ — นัดเจอกันที่สนามบิน
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'central',
            'region_label' => 'ภาคกลาง',
            'pickup_location' => 'สนามบินสุวรรณภูมิ',
            'price' => 55000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger()],
            ])
            ->assertCreated();
    }

    public function test_domestic_trip_is_untouched_by_the_new_rules(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);

        // ไม่ส่งเอกสารเดินทางเลย — ทริปในประเทศต้องจองผ่านเหมือนเดิม
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger([
                    'name_en' => null,
                    'passport_no' => null,
                    'passport_expires_at' => null,
                ])],
            ])
            ->assertCreated();
    }

    public function test_domestic_trip_still_requires_a_pickup_point(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);

        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'north',
            'region_label' => 'ภาคเหนือ',
            'pickup_location' => 'ประตูท่าแพ',
            'price' => 2500,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->passenger([
                    'name_en' => null,
                    'passport_no' => null,
                    'passport_expires_at' => null,
                ])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pickup_point_id']);
    }

    public function test_trips_can_be_filtered_by_destination(): void
    {
        $this->makeSchedule();
        $this->makeSchedule([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'northeast',
        ]);

        $international = $this->getJson('/api/v1/trips?destination=international')
            ->assertOk()
            ->json('data');
        $domestic = $this->getJson('/api/v1/trips?destination=domestic')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $international);
        $this->assertCount(1, $domestic);
        $this->assertTrue($international[0]['is_international']);
        $this->assertSame('🇳🇵 เนปาล', $international[0]['country_label']);
        $this->assertFalse($domestic[0]['is_international']);
        $this->assertNull($domestic[0]['country_label']);
    }

    public function test_trip_resource_exposes_the_destination_timezone(): void
    {
        $schedule = $this->makeSchedule();

        $payload = $this->getJson('/api/v1/trips/'.$schedule->trip->slug)
            ->assertOk()
            ->json('data');

        $this->assertSame('Asia/Kathmandu', $payload['destination_timezone']);
    }

    public function test_admin_set_timezone_wins_over_the_country_default(): void
    {
        $trip = $this->makeSchedule(['timezone' => 'America/Denver'])->trip;

        $this->assertSame('America/Denver', $trip->destinationTimezone());
    }

    public function test_domestic_trip_has_no_destination_timezone(): void
    {
        $trip = $this->makeSchedule([
            'destination_type' => 'domestic',
            'country_code' => 'NP', // ค่าค้างจากการสลับตัวเลือกต้องไม่มีผล
            'region' => 'north',
        ])->trip;

        $this->assertNull($trip->destinationTimezone());
        $this->assertNull($trip->countryLabel());
    }

    public function test_countries_endpoint_lists_supported_destinations(): void
    {
        $data = $this->getJson('/api/v1/countries')->assertOk()->json('data');

        $codes = array_column($data, 'code');
        $this->assertContains('NP', $codes);
        $this->assertContains('TH', $codes);

        $nepal = collect($data)->firstWhere('code', 'NP');
        $this->assertSame('เนปาล', $nepal['name']);
        $this->assertSame('Asia/Kathmandu', $nepal['timezone']);
    }

    public function test_unknown_country_codes_are_rejected(): void
    {
        $this->assertFalse(Countries::exists('ZZ'));
        $this->assertNull(Countries::name('ZZ'));
        $this->assertSame('', Countries::flag('ZZ'));
    }
}
