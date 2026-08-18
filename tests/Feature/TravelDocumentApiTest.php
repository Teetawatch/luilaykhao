<?php

namespace Tests\Feature;

use App\Jobs\SendTravelDocumentRemindersJob;
use App\Mail\PassportExpiringMail;
use App\Mail\PassportInfoNeededMail;
use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use App\Services\TravelDocumentService;
use App\Support\ThaiDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * เอกสารเดินทางในแอป — ตามเก็บพาสปอร์ตจากในแอปได้เอง ไม่ต้องผ่านลิงก์ในอีเมล
 *
 * ลิงก์ในอีเมลครอบเฉพาะคนที่เปิดอ่านอีเมล ส่วนแอปคือที่ที่ลูกค้าเปิดอยู่แล้วทุกวัน
 * ทั้งสองทางใช้ TravelDocumentService ตัวเดียวกัน จึงต้องตอบเหมือนกันทุกกรณี
 */
class TravelDocumentApiTest extends TestCase
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
            'transport_type' => 'flight',
            'status' => 'open',
        ]);
    }

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

    public function test_the_booking_payload_tells_the_app_what_is_still_missing(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk();

        $response->assertJsonPath('data.needs_passport_info', true);
        $response->assertJsonPath('data.passport.required', true);
        $response->assertJsonPath('data.passport.missing_count', 1);
        $response->assertJsonPath('data.passport.expiring_count', 0);
        $this->assertSame(
            $schedule->departure_date->copy()->addMonths(6)->toDateString(),
            $response->json('data.passport.minimum_expiry'),
        );
    }

    public function test_a_domestic_booking_never_asks_for_a_passport(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule([
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ]);
        $booking = $this->bookFromLegacyApp($user, $schedule);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.passport.required', false)
            ->assertJsonPath('data.passport.missing_count', 0);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [[
                    'id' => $booking->passengers->first()->id,
                    'name_en' => 'SOMCHAI JAIDEE',
                    'passport_no' => 'AA1234567',
                    'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_the_show_endpoint_lists_every_passenger_with_its_status(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule, [
            $this->legacyPassenger(),
            $this->legacyPassenger(['name' => 'สมหญิง ใจงาม', 'id_card' => '9876543210123']),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents")
            ->assertOk();

        $response->assertJsonPath('data.passport.missing_count', 2);
        $response->assertJsonPath('data.passengers.0.is_missing', true);
        $response->assertJsonPath('data.passengers.1.is_missing', true);
        $response->assertJsonPath('data.country', '🇳🇵 เนปาล');
        // ทะเบียนประเทศเดินทางมาให้ client เติม dropdown ได้เอง
        $this->assertNotEmpty($response->json('data.nationalities'));
    }

    public function test_customer_fills_the_passport_in_from_the_app(): void
    {
        $user = User::factory()->create(['name' => 'สมชาย ใจดี', 'id_card' => '1234567890123']);
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $passenger = $booking->passengers->first();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [[
                    'id' => $passenger->id,
                    'name_en' => 'somchai jaidee',
                    'passport_no' => 'aa1234567',
                    'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.passport.missing_count', 0)
            ->assertJsonPath('data.passengers.0.is_missing', false);

        $passenger->refresh();

        $this->assertSame('SOMCHAI JAIDEE', $passenger->name_en);
        $this->assertSame('AA1234567', $passenger->passport_no);
        // โปรไฟล์คนจองถูกเติมให้ด้วย เหมือนที่หน้าเว็บทำ
        $this->assertSame('AA1234567', $user->fresh()->passport_no);
    }

    public function test_the_six_month_rule_holds_in_the_app_too(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $passenger = $booking->passengers->first();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [[
                    'id' => $passenger->id,
                    'name_en' => 'SOMCHAI JAIDEE',
                    'passport_no' => 'AA1234567',
                    'passport_expires_at' => $schedule->departure_date->copy()->addMonths(5)->toDateString(),
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                "errors.passengers.{$passenger->id}",
                'สมชาย ใจดี: พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง',
            );

        $this->assertNull($passenger->fresh()->passport_no);
    }

    /** แถวที่ผิดแถวเดียวต้องไม่ทำให้ของคนอื่นถูกบันทึกครึ่ง ๆ กลาง ๆ */
    public function test_one_bad_row_saves_nothing_at_all(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule, [
            $this->legacyPassenger(),
            $this->legacyPassenger(['name' => 'สมหญิง ใจงาม', 'id_card' => '9876543210123']),
        ]);
        [$first, $second] = [$booking->passengers[0], $booking->passengers[1]];

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [
                    [
                        'id' => $first->id,
                        'name_en' => 'SOMCHAI JAIDEE',
                        'passport_no' => 'AA1234567',
                        'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
                    ],
                    [
                        'id' => $second->id,
                        'name_en' => 'SOMYING JAINGAM',
                        'passport_no' => '',
                        'passport_expires_at' => '',
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->assertNull($first->fresh()->passport_no);
        $this->assertNull($second->fresh()->passport_no);
    }

    /** เว้นว่างทั้งแถว = ยังไม่พร้อม ไม่ใช่กรอกผิด */
    public function test_blank_rows_are_skipped_not_rejected(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule, [
            $this->legacyPassenger(),
            $this->legacyPassenger(['name' => 'สมหญิง ใจงาม', 'id_card' => '9876543210123']),
        ]);
        [$first, $second] = [$booking->passengers[0], $booking->passengers[1]];

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [
                    [
                        'id' => $first->id,
                        'name_en' => 'SOMCHAI JAIDEE',
                        'passport_no' => 'AA1234567',
                        'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
                    ],
                    ['id' => $second->id, 'name_en' => '', 'passport_no' => '', 'passport_expires_at' => ''],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.passport.missing_count', 1);

        $this->assertSame('AA1234567', $first->fresh()->passport_no);
        $this->assertNull($second->fresh()->passport_no);
    }

    public function test_a_stranger_cannot_touch_someone_elses_documents(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($owner, $schedule);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents")
            ->assertStatus(403);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [[
                    'id' => $booking->passengers->first()->id,
                    'name_en' => 'HACKER MAN',
                    'passport_no' => 'ZZ9999999',
                    'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
                ]],
            ])
            ->assertStatus(403);

        $this->assertNull($booking->passengers->first()->fresh()->passport_no);
    }

    /** id ของผู้เดินทางในการจองอื่นถูกทิ้ง ไม่ใช่เขียนข้ามการจอง */
    public function test_a_passenger_id_from_another_booking_is_ignored(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $mine = $this->bookFromLegacyApp($user, $schedule);

        $otherOwner = User::factory()->create();
        $otherSchedule = $this->makeSchedule();
        $theirs = $this->bookFromLegacyApp($otherOwner, $otherSchedule);
        $theirPassenger = $theirs->passengers->first();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$mine->booking_ref}/travel-documents", [
                'passengers' => [[
                    'id' => $theirPassenger->id,
                    'name_en' => 'SOMCHAI JAIDEE',
                    'passport_no' => 'AA1234567',
                    'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
                ]],
            ])
            ->assertOk();

        $this->assertNull($theirPassenger->fresh()->passport_no);
    }

    // ── เตือนล่วงหน้า ────────────────────────────────────────────────────

    public function test_the_reminder_chases_a_booking_that_never_filled_it_in(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
        ]);
        $this->bookFromLegacyApp($user, $schedule);

        // ดักอีเมลหลังจองแล้ว — ฉบับที่ BookingService ส่งตอนจองไม่ใช่ของที่กำลังทดสอบ
        Mail::fake();

        (new SendTravelDocumentRemindersJob)->handle(
            app(MailService::class),
            app(TravelDocumentService::class),
        );

        Mail::assertQueued(PassportInfoNeededMail::class);
    }

    /**
     * พาสปอร์ตที่ผ่านเกณฑ์ตอนจอง แต่รอบถูกเลื่อนออกไปจนเล่มตกเกณฑ์ 6 เดือน
     * ต้องมีคนไล่ตรวจซ้ำ ไม่ใช่รู้ตัวที่เคาน์เตอร์เช็คอิน
     */
    public function test_the_reminder_catches_a_passport_that_expires_too_soon(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);

        Mail::fake();

        $booking->passengers->first()->update([
            'name_en' => 'SOMCHAI JAIDEE',
            'passport_no' => 'AA1234567',
            // ยังไม่หมดอายุ แต่เหลือไม่ถึง 6 เดือนนับจากวันเดินทางใหม่
            'passport_expires_at' => now('Asia/Bangkok')->addDays(60)->toDateString(),
        ]);
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(45)->toDateString(),
        ]);

        (new SendTravelDocumentRemindersJob)->handle(
            app(MailService::class),
            app(TravelDocumentService::class),
        );

        Mail::assertQueued(PassportExpiringMail::class);
        Mail::assertNotQueued(PassportInfoNeededMail::class);
    }

    public function test_a_complete_booking_is_left_alone(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
        ]);
        $booking = $this->bookFromLegacyApp($user, $schedule);

        Mail::fake();

        $booking->passengers->first()->update([
            'name_en' => 'SOMCHAI JAIDEE',
            'passport_no' => 'AA1234567',
            'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
        ]);

        (new SendTravelDocumentRemindersJob)->handle(
            app(MailService::class),
            app(TravelDocumentService::class),
        );

        Mail::assertNotQueued(PassportInfoNeededMail::class);
        Mail::assertNotQueued(PassportExpiringMail::class);
    }

    /**
     * เมลฉบับใหม่ต้อง render ได้จริง — assertQueued ไม่ได้แตะ blade เลย
     * บั๊กใน blade จะไปโผล่ตอนส่งจริงบนโปรดักชันเท่านั้น
     */
    public function test_the_expiring_email_renders_the_passenger_and_deadline(): void
    {
        $user = User::factory()->create(['name' => 'สมชาย ใจดี']);
        $schedule = $this->makeSchedule();
        $booking = $this->bookFromLegacyApp($user, $schedule);
        $booking->passengers->first()->update([
            'name_en' => 'SOMCHAI JAIDEE',
            'passport_no' => 'AA1234567',
            'passport_expires_at' => now('Asia/Bangkok')->addDays(60)->toDateString(),
        ]);
        $booking->refresh()->load(['user', 'passengers', 'schedule.trip']);

        $html = (new PassportExpiringMail($booking, $booking->passportUrl(), 21))->render();

        $this->assertStringContainsString('พาสปอร์ตใกล้หมดอายุ', $html);
        $this->assertStringContainsString('สมชาย ใจดี', $html);
        $this->assertStringContainsString('21 วัน', $html);
        // วันหมดอายุที่เร็วที่สุดที่ยังรับได้ ต้องบอกเป็นวันจริง ไม่ใช่ "6 เดือน" ลอย ๆ
        $this->assertStringContainsString(
            ThaiDate::full($schedule->departure_date->copy()->addMonths(6)),
            $html,
        );
    }

    /** วันที่ไม่ตรงหมุด 45/21/10 ต้องเงียบ ไม่ใช่ส่งทุกวันจนลูกค้ารำคาญ */
    public function test_the_reminder_only_fires_on_its_own_milestones(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
        ]);
        $this->bookFromLegacyApp($user, $schedule);

        Mail::fake();

        (new SendTravelDocumentRemindersJob)->handle(
            app(MailService::class),
            app(TravelDocumentService::class),
        );

        Mail::assertNotQueued(PassportInfoNeededMail::class);
    }
}
