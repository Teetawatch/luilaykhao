<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * โหมดเอกสารเดินทางของทริปต่างประเทศ (trips.travel_document_mode)
 *
 * - passport (ค่าเดิม) — บังคับพาสปอร์ตทุกคน
 * - passport_or_border_pass — ลาว/กัมพูชาทางบก: คนไทยที่ไม่มีพาสปอร์ตเลือกให้
 *   ทีมงานทำ Border Pass จากเลขบัตรประชาชนได้
 * - on_site — ไม่ถามเลย ทีมงานตรวจฉบับจริงหน้างาน
 */
class TravelDocumentModeTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::slipDisk());
    }

    private function makeSchedule(?string $mode, array $tripOverrides = []): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'ลาวใต้ ปากเซ',
            'slug' => 'laos-south-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Pakse',
            'destination_type' => 'international',
            'country_code' => 'LA',
            'travel_document_mode' => $mode,
            'difficulty' => 'easy',
            'duration_days' => 3,
            'max_participants' => 12,
            'price_per_person' => 6900,
            'status' => 'active',
        ], $tripOverrides));

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonths(2)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonths(2)->addDays(3)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function passenger(array $overrides = []): array
    {
        return array_merge([
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'id_card' => '1234567890123',
            'name_en' => null,
            'passport_no' => null,
            'passport_expires_at' => null,
            'phone' => '0810000000',
            'blood_group' => 'A',
            'halal_food' => false,
            'emergency_contact' => 'สมหญิง',
            'emergency_phone' => '0820000000',
        ], $overrides);
    }

    private function withPassport(array $overrides = []): array
    {
        return $this->passenger(array_merge([
            'travel_document' => 'passport',
            'name_en' => 'SOMCHAI JAIDEE',
            'passport_no' => 'AA1234567',
            'passport_expires_at' => now('Asia/Bangkok')->addYears(3)->toDateString(),
        ], $overrides));
    }

    private function book(TripSchedule $schedule, array $passengers)
    {
        return $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $passengers,
            ]);
    }

    private function bookingWith(TripSchedule $schedule, array $passengers): Booking
    {
        $booking = Booking::create([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 6900,
            'paid_amount' => 6900,
        ]);

        foreach ($passengers as $passenger) {
            BookingPassenger::create(['booking_id' => $booking->id, ...$passenger]);
        }

        return $booking->load('passengers', 'schedule.trip');
    }

    /* ── ค่าเริ่มต้น ─────────────────────────────────────────────── */

    public function test_an_international_trip_without_a_mode_still_requires_a_passport(): void
    {
        $trip = $this->makeSchedule(null)->trip;

        $this->assertSame(Trip::DOCS_PASSPORT, $trip->travelDocumentMode());
        $this->assertTrue($trip->needsPassportFor(Trip::DOC_BORDER_PASS, 'TH'));
    }

    public function test_a_domestic_trip_has_no_mode_even_if_one_is_stored(): void
    {
        $trip = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS, [
            'destination_type' => 'domestic',
            'country_code' => null,
            'region' => 'north',
        ])->trip;

        $this->assertNull($trip->travelDocumentMode());
        $this->assertFalse($trip->collectsPassport());
        $this->assertNull($trip->resolveTravelDocument(Trip::DOC_BORDER_PASS, 'TH'));
    }

    public function test_the_trip_payload_tells_forms_which_mode_to_draw(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        $this->getJson("/api/v1/trips/{$schedule->trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.travel_document_mode', 'passport_or_border_pass');
    }

    /* ── พาสปอร์ต หรือ Border Pass ──────────────────────────────── */

    public function test_a_thai_traveller_without_a_passport_books_with_a_border_pass(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        $this->book($schedule, [$this->passenger(['travel_document' => 'border_pass'])])
            ->assertCreated();

        $passenger = $schedule->bookings()->first()->passengers()->first();
        $this->assertSame('border_pass', $passenger->travel_document);
        $this->assertNull($passenger->passport_no);
        $this->assertFalse($schedule->bookings()->first()->needsPassportInfo());
    }

    public function test_a_border_pass_traveller_does_not_keep_a_stale_passport(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        // ฟอร์มเติมพาสปอร์ตเก่าจากโปรไฟล์ไว้ แต่เจ้าตัวเลือก Border Pass
        $this->book($schedule, [$this->withPassport(['travel_document' => 'border_pass'])])
            ->assertCreated();

        $passenger = $schedule->bookings()->first()->passengers()->first();
        $this->assertSame('border_pass', $passenger->travel_document);
        $this->assertNull($passenger->name_en);
        $this->assertNull($passenger->passport_no);
        $this->assertNull($passenger->passport_expires_at);
    }

    public function test_choosing_passport_still_requires_the_passport_details(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        $this->book($schedule, [$this->passenger(['travel_document' => 'passport'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['passengers.0.passport_no', 'passengers.0.passport_expires_at']);

        $this->book($schedule, [$this->withPassport()])->assertCreated();
        $this->assertSame('passport', BookingPassenger::first()->travel_document);
    }

    public function test_a_client_that_never_answers_is_treated_as_passport(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        // เว็บ/แอปรุ่นก่อนหน้ามีแต่ช่องพาสปอร์ต ไม่รู้จัก travel_document
        $this->book($schedule, [$this->passenger()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['passengers.0.passport_no']);
    }

    public function test_a_foreigner_cannot_pick_a_border_pass(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        $this->book($schedule, [$this->passenger([
            'nationality' => 'JP',
            'name' => 'Taro Yamada',
            'id_card' => null,
            'phone' => '+81 90 1234 5678',
            'travel_document' => 'border_pass',
        ])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['passengers.0.travel_document']);
    }

    public function test_the_six_month_rule_skips_border_pass_travellers(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        $this->book($schedule, [$this->withPassport([
            'travel_document' => 'border_pass',
            'passport_expires_at' => $schedule->departure_date->copy()->addMonth()->toDateString(),
        ])])->assertCreated();
    }

    public function test_a_passport_only_trip_ignores_a_border_pass_answer(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT);

        $this->book($schedule, [$this->passenger(['travel_document' => 'border_pass'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['passengers.0.passport_no']);

        $this->book($schedule, [$this->withPassport(['travel_document' => 'border_pass'])])->assertCreated();
        $this->assertNull(BookingPassenger::first()->travel_document);
    }

    public function test_follow_up_counts_only_travellers_who_need_a_passport(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);
        $booking = $this->bookingWith($schedule, [
            ['name' => 'คนทำบอร์เดอร์ พาส', 'nationality' => 'TH', 'travel_document' => 'border_pass'],
            ['name' => 'คนมีพาส ปอร์ต', 'nationality' => 'TH', 'travel_document' => 'passport'],
            ['name' => 'คนยังไม่ เลือก', 'nationality' => 'TH'],
        ]);

        $this->assertSame(
            ['คนมีพาส ปอร์ต', 'คนยังไม่ เลือก'],
            $booking->passengersMissingPassport()->pluck('name')->values()->all(),
        );
    }

    public function test_the_follow_up_page_lets_a_traveller_switch_to_border_pass(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);
        $booking = $this->bookingWith($schedule, [
            ['name' => 'สมชาย ใจดี', 'nationality' => 'TH', 'id_card' => '1234567890123'],
        ]);
        $passenger = $booking->passengers->first();
        $token = $booking->ensurePassportToken();

        $this->get("/booking-passport/{$token}")->assertOk()->assertSee('Border Pass');

        $this->post("/booking-passport/{$token}", [
            'travel_document' => [$passenger->id => 'border_pass'],
        ])->assertRedirect();

        $this->assertSame('border_pass', $passenger->fresh()->travel_document);
        $this->assertFalse($booking->fresh()->needsPassportInfo());
    }

    public function test_the_app_can_save_a_border_pass_choice(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);
        $booking = $this->bookingWith($schedule, [
            ['name' => 'สมชาย ใจดี', 'nationality' => 'TH', 'id_card' => '1234567890123'],
            ['name' => 'Taro Yamada', 'nationality' => 'JP'],
        ]);
        [$thai, $foreigner] = $booking->passengers->all();

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents")
            ->assertOk()
            ->assertJsonPath('data.passport.offers_border_pass', true)
            ->assertJsonPath('data.passengers.0.can_border_pass', true)
            ->assertJsonPath('data.passengers.1.can_border_pass', false);

        $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [['id' => $foreigner->id, 'travel_document' => 'border_pass']],
            ])
            ->assertStatus(422);

        $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/travel-documents", [
                'passengers' => [['id' => $thai->id, 'travel_document' => 'border_pass']],
            ])
            ->assertOk()
            ->assertJsonPath('data.passengers.0.travel_document', 'border_pass')
            ->assertJsonPath('data.passengers.0.is_missing', false);
    }

    public function test_a_friend_filling_their_own_details_can_pick_border_pass(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);
        $booking = $this->bookingWith($schedule, [[
            'name' => 'ผู้ร่วมทริปคนที่ 2',
            'nationality' => 'TH',
            'self_fill_token' => 'border-pass-token',
            'self_fill_expires_at' => now()->addDay(),
        ]]);

        $form = [
            'name' => 'สมหญิง ใจดี',
            'phone' => '0812345678',
            'travel_document' => 'border_pass',
        ];

        // Border Pass ยื่นจากเลขบัตร — ขาดเลขบัตรทีมงานทำให้ไม่ได้
        $this->post('/p/border-pass-token', $form)->assertSessionHasErrors('id_card');

        // ด่านขอรูปบัตรประชาชนหน้า/หลังด้วย
        $this->post('/p/border-pass-token', [...$form, 'id_card' => '1234567890123'])
            ->assertSessionHasErrors(['border_pass_front', 'border_pass_back']);

        $this->post('/p/border-pass-token', [
            ...$form,
            'id_card' => '1234567890123',
            'border_pass_front' => UploadedFile::fake()->image('front.jpg'),
            'border_pass_back' => UploadedFile::fake()->image('back.jpg'),
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, BookingDocument::where('booking_passenger_id', $booking->passengers->first()->id)->count());

        $passenger = $booking->passengers->first()->fresh();
        $this->assertSame('border_pass', $passenger->travel_document);
        $this->assertNull($passenger->passport_no);
    }

    public function test_admin_overview_lists_who_needs_a_border_pass(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);
        $this->bookingWith($schedule, [
            ['name' => 'คนทำบอร์เดอร์ พาส', 'nationality' => 'TH', 'travel_document' => 'border_pass'],
            ['name' => 'คนมีพาส ปอร์ต', 'nationality' => 'TH', 'travel_document' => 'passport', 'passport_no' => 'AA1234567'],
        ]);

        $event = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk()
            ->json('data'))->firstWhere('id', $schedule->id);

        $this->assertSame(1, $event['border_pass_count']);
        $this->assertSame(
            ['border_pass', 'passport'],
            collect($event['passenger_manifest'])->pluck('travel_document')->all(),
        );
    }

    /* ── ใช้ฉบับจริงหน้างาน ─────────────────────────────────────── */

    public function test_an_on_site_trip_books_without_any_passport(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_ON_SITE);

        $this->book($schedule, [$this->passenger()])->assertCreated();

        $booking = $schedule->bookings()->first();
        $this->assertFalse($booking->needsPassportInfo());

        // ลิงก์ตามเก็บพาสปอร์ตไม่มีความหมายกับทริปนี้
        $this->get('/booking-passport/'.$booking->ensurePassportToken())->assertNotFound();
    }

    public function test_an_on_site_trip_does_not_keep_passport_numbers_it_never_asked_for(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_ON_SITE);

        $this->book($schedule, [$this->withPassport()])->assertCreated();

        $passenger = BookingPassenger::first();
        $this->assertNull($passenger->passport_no);
        $this->assertNull($passenger->travel_document);
    }

    /* ── แอดมินเปิดจองแทนลูกค้า (ดึงมาจากลิงก์ที่ลูกค้ากรอกเอง) ───── */

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function manualBooking(TripSchedule $schedule, array $passengers)
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'manual@example.test',
                'passengers' => array_map(fn ($passenger) => array_merge([
                    'title' => 'นาย',
                    'phone' => '0810000000',
                    'id_card' => '1234567890123',
                    'emergency_contact' => 'สมหญิง',
                    'emergency_phone' => '0820000000',
                    'halal_food' => false,
                ], $passenger), $passengers),
                'status' => 'pending',
                'payment_type' => 'full',
                'send_email' => false,
            ]);
    }

    public function test_manual_booking_keeps_the_documents_the_customer_filled_in(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_PASSPORT_OR_BORDER_PASS);

        $response = $this->manualBooking($schedule, [
            ['name' => 'สมชาย ใจดี', 'travel_document' => 'border_pass', 'passport_no' => 'OLD12345'],
            ['name' => 'สมหญิง ใจงาม', 'travel_document' => 'passport', 'name_en' => 'somying jaingam',
                'passport_no' => 'aa1234567', 'passport_expires_at' => now()->addYears(3)->toDateString()],
        ])->assertCreated();

        $byName = Booking::with('passengers')->findOrFail($response->json('data.id'))->passengers->keyBy('name');

        $this->assertSame('border_pass', $byName['สมชาย ใจดี']->travel_document);
        $this->assertNull($byName['สมชาย ใจดี']->passport_no);
        $this->assertSame('passport', $byName['สมหญิง ใจงาม']->travel_document);
        $this->assertSame('AA1234567', $byName['สมหญิง ใจงาม']->passport_no);
        $this->assertSame('SOMYING JAINGAM', $byName['สมหญิง ใจงาม']->name_en);
    }

    public function test_manual_booking_on_an_on_site_trip_stores_no_passport(): void
    {
        $schedule = $this->makeSchedule(Trip::DOCS_ON_SITE);

        $response = $this->manualBooking($schedule, [
            ['name' => 'สมชาย ใจดี', 'passport_no' => 'AA1234567'],
        ])->assertCreated();

        $this->assertNull(Booking::with('passengers')->findOrFail($response->json('data.id'))->passengers->first()->passport_no);
    }

    /* ── แอดมินตั้งค่า ─────────────────────────────────────────── */

    public function test_admin_rejects_an_unknown_mode(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $trip = $this->makeSchedule(null)->trip;

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", [
                'title' => $trip->title,
                'type' => 'trekking',
                'location' => $trip->location,
                'destination_type' => 'international',
                'country_code' => 'LA',
                'travel_document_mode' => 'visa_on_arrival',
                'difficulty' => 'easy',
                'duration_days' => 3,
                'max_participants' => 12,
                'price_per_person' => 6900,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('travel_document_mode');
    }
}
