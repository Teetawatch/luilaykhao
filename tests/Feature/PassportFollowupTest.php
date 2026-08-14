<?php

namespace Tests\Feature;

use App\Mail\PassportInfoNeededMail;
use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ตามเก็บพาสปอร์ตของทริปต่างประเทศ
 *
 * แอปที่ลูกค้าติดตั้งอยู่ก่อนรุ่นที่รองรับทริปต่างประเทศไม่มีช่องกรอกเอกสาร
 * เดินทาง ถ้าบังคับกับทุกคำขอ ลูกค้ากลุ่มนั้นจะกดจองไม่ผ่านจนกว่าจะอัปเดต
 * ซึ่งกินเวลาหลายสัปดาห์และมีคนที่ไม่อัปเดตเลย — จองผ่านไปก่อนแล้วตามเก็บ
 * ด้วยลิงก์บนเว็บ ดีกว่าปิดประตูขาย
 */
class PassportFollowupTest extends TestCase
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

    /** ชุดข้อมูลเท่าที่แอปรุ่นเก่ารู้จัก — ไม่มีคีย์เอกสารเดินทางเลยสักคีย์ */
    private function legacyPassenger(array $overrides = []): array
    {
        return array_merge([
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'id_card' => '1234567890123',
            'phone' => '0810000000',
            'blood_group' => 'A',
            'halal_food' => false,
            'emergency_contact' => 'สมหญิง',
            'emergency_phone' => '0820000000',
        ], $overrides);
    }

    private function bookFromLegacyApp(User $user, TripSchedule $schedule, ?array $passengers = null): Booking
    {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $passengers ?? [$this->legacyPassenger()],
            ])
            ->assertCreated();

        return $schedule->bookings()->with(['passengers', 'schedule.trip'])->first();
    }

    public function test_app_version_without_passport_fields_can_still_book(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = $this->bookFromLegacyApp($user, $schedule);

        $this->assertNull($booking->passengers->first()->passport_no);
        $this->assertTrue($booking->needsPassportInfo());
    }

    /** ส่งคีย์มาแต่ปล่อยว่าง = ช่องทางนั้นถามแล้ว ต้องเตือนที่หน้าจองเหมือนเดิม */
    public function test_client_that_asks_for_passport_must_still_fill_it_in(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [$this->legacyPassenger([
                    'name_en' => '',
                    'passport_no' => '',
                    'passport_expires_at' => '',
                ])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'passengers.0.name_en',
                'passengers.0.passport_no',
                'passengers.0.passport_expires_at',
            ]);
    }

    public function test_booking_without_passport_gets_a_follow_up_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = $this->bookFromLegacyApp($user, $schedule);

        Mail::assertQueued(PassportInfoNeededMail::class);

        // เนื้ออีเมลต้องเรนเดอร์ได้จริงและมีลิงก์กรอกอยู่ในนั้น
        $html = (new PassportInfoNeededMail($booking, $booking->passportUrl()))->render();

        $this->assertStringContainsString($booking->passport_token, $html);
    }

    public function test_domestic_booking_gets_no_follow_up_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $schedule = $this->makeSchedule(['destination_type' => 'domestic', 'country_code' => null]);

        $this->bookFromLegacyApp($user, $schedule);

        Mail::assertNotQueued(PassportInfoNeededMail::class);
    }

    public function test_customer_fills_the_passport_in_later_from_the_link(): void
    {
        $user = User::factory()->create(['name' => 'สมชาย ใจดี', 'id_card' => '1234567890123']);
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $passenger = $booking->passengers->first();
        $token = $booking->ensurePassportToken();

        $this->get("/booking-passport/{$token}")->assertOk();

        $this->post("/booking-passport/{$token}", [
            'name_en' => [$passenger->id => 'somchai jaidee'],
            'passport_no' => [$passenger->id => 'aa1234567'],
            'passport_expires_at' => [$passenger->id => now('Asia/Bangkok')->addYears(4)->toDateString()],
        ])->assertRedirect(route('public.passport.show', $token));

        $passenger->refresh();

        $this->assertSame('SOMCHAI JAIDEE', $passenger->name_en);
        $this->assertSame('AA1234567', $passenger->passport_no);
        $this->assertFalse($booking->fresh()->load(['passengers', 'schedule.trip'])->needsPassportInfo());

        // โปรไฟล์ของคนจองถูกเติมให้ด้วย การจองครั้งหน้าจะได้ไม่ต้องพิมพ์ใหม่
        $this->assertSame('AA1234567', $user->fresh()->passport_no);
    }

    public function test_the_six_month_rule_holds_on_the_follow_up_page(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $passenger = $booking->passengers->first();
        $token = $booking->ensurePassportToken();

        $this->post("/booking-passport/{$token}", [
            'name_en' => [$passenger->id => 'SOMCHAI JAIDEE'],
            'passport_no' => [$passenger->id => 'AA1234567'],
            'passport_expires_at' => [$passenger->id => $schedule->departure_date->copy()->addMonths(5)->toDateString()],
        ])->assertSessionHasErrors('passengers');

        $this->assertNull($passenger->fresh()->passport_no);
    }

    public function test_a_half_filled_passenger_is_rejected(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $passenger = $booking->passengers->first();
        $token = $booking->ensurePassportToken();

        $this->post("/booking-passport/{$token}", [
            'name_en' => [$passenger->id => 'SOMCHAI JAIDEE'],
            'passport_no' => [$passenger->id => ''],
            'passport_expires_at' => [$passenger->id => ''],
        ])->assertSessionHasErrors('passengers');

        $this->assertNull($passenger->fresh()->name_en);
    }

    /** เพื่อนที่ยังไม่พร้อมกรอก เว้นว่างไว้ได้ ไม่ลบของคนที่กรอกไปแล้ว */
    public function test_blank_rows_are_left_alone(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule, [
            $this->legacyPassenger(),
            $this->legacyPassenger(['name' => 'สมหญิง ใจงาม', 'id_card' => '9876543210123']),
        ]);
        [$first, $second] = [$booking->passengers[0], $booking->passengers[1]];
        $token = $booking->ensurePassportToken();

        $this->post("/booking-passport/{$token}", [
            'name_en' => [$first->id => 'SOMCHAI JAIDEE', $second->id => ''],
            'passport_no' => [$first->id => 'AA1234567', $second->id => ''],
            'passport_expires_at' => [
                $first->id => now('Asia/Bangkok')->addYears(4)->toDateString(),
                $second->id => '',
            ],
        ])->assertRedirect(route('public.passport.show', $token));

        $this->assertSame('AA1234567', $first->fresh()->passport_no);
        $this->assertNull($second->fresh()->passport_no);
    }

    public function test_the_link_is_dead_for_a_cancelled_booking(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $token = $booking->ensurePassportToken();

        $booking->update(['status' => 'cancelled']);

        $this->get("/booking-passport/{$token}")->assertNotFound();
        $this->get('/booking-passport/notarealtoken')->assertNotFound();
    }

    public function test_admin_sees_the_booking_until_the_passport_arrives(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $passenger = $booking->passengers->first();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/passport-followup')
            ->assertOk();

        $row = $response->json('data.0');

        $this->assertSame($booking->booking_ref, $row['booking_ref']);
        $this->assertSame(1, $row['missing_count']);
        $this->assertStringContainsString('/booking-passport/', $row['link']);

        $passenger->update([
            'name_en' => 'SOMCHAI JAIDEE',
            'passport_no' => 'AA1234567',
            'passport_expires_at' => now('Asia/Bangkok')->addYears(4)->toDateString(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/passport-followup')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
