<?php

namespace Tests\Feature;

use App\Jobs\PurgeBorderPassDocumentsJob;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingPassenger;
use App\Models\CustomerIntake;
use App\Models\CustomerIntakePerson;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingDocumentService;
use App\Support\MediaDisk;
use App\Support\TripDocumentRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รูปบัตรประชาชนหน้า/หลังของคนทำ Border Pass — ด่านบังคับใช้เป็นหลักฐาน
 *
 * สิ่งที่ต้องถูก: ขอเฉพาะคนที่เลือก Border Pass, ใบละหนึ่งไฟล์ต่อด้าน (แนบใหม่ =
 * แทนที่), เปิดดูได้เฉพาะเจ้าของใบจองกับทีมงาน และไฟล์ไม่ค้างเกินจำเป็น
 */
class BorderPassIdDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::slipDisk());
    }

    private function makeSchedule(?string $mode = Trip::DOCS_PASSPORT_OR_BORDER_PASS, int $daysAhead = 30): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ลาวใต้ ปากเซ',
            'slug' => 'laos-'.uniqid(),
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
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysAhead)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysAhead + 2)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /** @return array{0: Booking, 1: BookingPassenger, 2: BookingPassenger} คนทำ Border Pass + คนมีพาสปอร์ต */
    private function makeBooking(TripSchedule $schedule, string $status = 'confirmed'): array
    {
        $booking = Booking::create([
            'booking_ref' => sprintf('LLK-20261101-%04d', ++$this->refSeq),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 13800,
            'paid_amount' => 13800,
        ]);

        $border = BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี', 'nationality' => 'TH',
            'id_card' => '1101700207251', 'travel_document' => Trip::DOC_BORDER_PASS,
        ]);
        $passport = BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'มานี รักเรียน', 'nationality' => 'TH',
            'id_card' => '1234567890123', 'travel_document' => Trip::DOC_PASSPORT,
            'name_en' => 'MANEE RAKRIAN', 'passport_no' => 'AA1234567',
            'passport_expires_at' => now()->addYears(3)->toDateString(),
        ]);

        return [$booking->load('passengers', 'schedule.trip'), $border, $passport];
    }

    private function upload(Booking $booking, BookingPassenger $passenger, string $key, ?User $as = null)
    {
        return $this->actingAs($as ?? $booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $passenger->id,
                'requirement_key' => $key,
                'file' => UploadedFile::fake()->image('id.jpg'),
            ]);
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_the_trip_payload_lists_the_id_card_slots_only_for_border_pass_trips(): void
    {
        $laos = $this->makeSchedule();
        $nepal = $this->makeSchedule(Trip::DOCS_PASSPORT);

        $this->getJson("/api/v1/trips/{$laos->trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.border_pass_documents.0.key', TripDocumentRequirements::BORDER_PASS_FRONT)
            ->assertJsonPath('data.border_pass_documents.1.key', TripDocumentRequirements::BORDER_PASS_BACK)
            // ไม่ปนกับรายการของทุกคน แอปรุ่นเก่าจะได้ไม่บังคับทุกคนแนบบัตร
            ->assertJsonCount(0, 'data.document_requirements');

        $this->getJson("/api/v1/trips/{$nepal->trip->slug}")
            ->assertJsonCount(0, 'data.border_pass_documents');
    }

    public function test_only_the_border_pass_traveller_is_asked_for_the_id_card(): void
    {
        [$booking, $border, $passport] = $this->makeBooking($this->makeSchedule());

        $payload = $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/documents")
            ->assertOk()
            ->json('data');

        $byPassenger = collect($payload['passengers'])->keyBy('passenger_id');
        $this->assertSame(
            TripDocumentRequirements::BORDER_PASS_KEYS,
            collect($byPassenger[$border->id]['requirements'])->pluck('key')->all(),
        );
        $this->assertSame([], $byPassenger[$passport->id]['requirements']);
        $this->assertTrue($payload['has_missing']);

        $missing = app(BookingDocumentService::class)->missingRequirements($booking);
        $this->assertSame(['สมชาย ใจดี'], $missing[0]['passengers']);
    }

    public function test_a_passport_holder_cannot_upload_an_id_card_for_border_pass(): void
    {
        [$booking, , $passport] = $this->makeBooking($this->makeSchedule());

        $this->upload($booking, $passport, TripDocumentRequirements::BORDER_PASS_FRONT)
            ->assertStatus(422)
            ->assertJsonPath('message', 'บัตรประชาชนใช้สำหรับคนที่เลือกทำ Border Pass เท่านั้น');
    }

    public function test_a_new_photo_replaces_the_old_one(): void
    {
        [$booking, $border] = $this->makeBooking($this->makeSchedule());

        $this->upload($booking, $border, TripDocumentRequirements::BORDER_PASS_FRONT)->assertCreated();
        $first = BookingDocument::firstOrFail();

        $this->upload($booking, $border, TripDocumentRequirements::BORDER_PASS_FRONT)->assertCreated();

        $this->assertSame(1, BookingDocument::where('requirement_key', TripDocumentRequirements::BORDER_PASS_FRONT)->count());
        Storage::disk(MediaDisk::slipDisk())->assertMissing($first->file_path);
    }

    public function test_a_stranger_cannot_see_or_upload_id_cards(): void
    {
        [$booking, $border] = $this->makeBooking($this->makeSchedule());
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/documents")
            ->assertForbidden();
        $this->upload($booking, $border, TripDocumentRequirements::BORDER_PASS_FRONT, $stranger)->assertForbidden();
    }

    public function test_switching_to_passport_deletes_the_id_card_right_away(): void
    {
        [$booking, $border] = $this->makeBooking($this->makeSchedule());
        $this->upload($booking, $border, TripDocumentRequirements::BORDER_PASS_FRONT)->assertCreated();
        $path = BookingDocument::firstOrFail()->file_path;

        $border->update(['travel_document' => Trip::DOC_PASSPORT]);

        $this->assertSame(0, BookingDocument::count());
        Storage::disk(MediaDisk::slipDisk())->assertMissing($path);
    }

    public function test_the_purge_job_clears_finished_and_cancelled_trips_only(): void
    {
        [$upcoming, $a] = $this->makeBooking($this->makeSchedule());
        [$finished, $b] = $this->makeBooking($this->makeSchedule(daysAhead: -(PurgeBorderPassDocumentsJob::RETENTION_DAYS + 5)));
        [$cancelled, $c] = $this->makeBooking($this->makeSchedule(), 'cancelled');

        foreach ([[$upcoming, $a], [$finished, $b], [$cancelled, $c]] as [$booking, $passenger]) {
            app(BookingDocumentService::class)->store(
                $booking, $passenger, TripDocumentRequirements::BORDER_PASS_FRONT, UploadedFile::fake()->image('id.jpg'), null,
            );
        }

        (new PurgeBorderPassDocumentsJob)->handle(app(BookingDocumentService::class));

        $this->assertSame([$a->id], BookingDocument::pluck('booking_passenger_id')->all());
    }

    public function test_the_no_login_follow_up_page_takes_the_id_card_photos(): void
    {
        [$booking, $border] = $this->makeBooking($this->makeSchedule());
        $token = $booking->ensurePassportToken();

        $this->get("/booking-passport/{$token}")
            ->assertOk()
            ->assertSee('บัตรประชาชน (ด้านหน้า)')
            ->assertSee('enctype="multipart/form-data"', false);

        $this->post("/booking-passport/{$token}", [
            'travel_document' => [$border->id => 'border_pass'],
            'border_pass_front' => [$border->id => UploadedFile::fake()->image('front.jpg')],
            'border_pass_back' => [$border->id => UploadedFile::fake()->image('back.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], app(BookingDocumentService::class)->missingRequirements($booking->fresh()));
    }

    public function test_the_follow_up_page_renders_inside_its_layout(): void
    {
        [$booking] = $this->makeBooking($this->makeSchedule());

        $html = $this->get('/booking-passport/'.$booking->ensurePassportToken())->getContent();

        // เคยมีบล็อกฟอร์มหลุดไปอยู่ก่อน layout — หน้าแสดงได้แต่ฟอร์มจริงยังเป็นแบบเก่า
        $this->assertStringStartsWith('<!doctype', strtolower(ltrim($html)));
        $this->assertSame(2, substr_count($html, 'class="pax" data-travel-doc'));
        $this->assertGreaterThan(strpos($html, '<form'), strpos($html, 'class="pax" data-travel-doc'));
    }

    public function test_admin_sees_the_round_border_pass_list_with_photos(): void
    {
        $schedule = $this->makeSchedule();
        [$booking, $border] = $this->makeBooking($schedule);
        app(BookingDocumentService::class)->store(
            $booking, $border, TripDocumentRequirements::BORDER_PASS_FRONT, UploadedFile::fake()->image('front.jpg'), null,
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/border-pass")
            ->assertOk()
            ->assertJsonCount(1, 'data.passengers')
            ->assertJsonPath('data.passengers.0.id_card', '1101700207251')
            ->assertJsonPath('data.passengers.0.back', null)
            ->assertJsonPath('data.missing_count', 1)
            ->assertJsonStructure(['data' => ['passengers' => [['front' => ['url']]]]]);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/border-pass")
            ->assertForbidden();
    }

    public function test_admin_booking_from_an_intake_link_brings_the_photos_along(): void
    {
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $schedule = $this->makeSchedule();
        $disk = Storage::disk(MediaDisk::slipDisk());
        $disk->put('booking-documents/intake/front.jpg', 'x');
        $disk->put('booking-documents/intake/back.jpg', 'x');

        $intake = new CustomerIntake([
            'trip_schedule_id' => $schedule->id, 'status' => 'new',
            'party_size' => 1, 'contact_name' => 'สมชาย ใจดี', 'contact_phone' => '0810000000',
        ]);
        $intake->token = 'intake-'.uniqid();
        $intake->save();
        $person = CustomerIntakePerson::create([
            'customer_intake_id' => $intake->id, 'is_lead' => true, 'name' => 'สมชาย ใจดี',
            'id_card' => '1101700207251', 'travel_document' => Trip::DOC_BORDER_PASS,
        ]);
        $person->forceFill([
            'border_pass_front_path' => 'booking-documents/intake/front.jpg',
            'border_pass_back_path' => 'booking-documents/intake/back.jpg',
        ])->save();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'intake@example.test',
                // แอดมินพิมพ์เลขบัตรมีขีดก็ยังจับคู่ได้
                'passengers' => [[
                    'title' => 'นาย', 'name' => 'สมชาย ใจดี', 'phone' => '0810000000',
                    'id_card' => '1-1017-00207-25-1', 'travel_document' => 'border_pass',
                    'emergency_contact' => 'แม่', 'emergency_phone' => '0820000000', 'halal_food' => false,
                ]],
                'intake_ids' => [$intake->id],
                'status' => 'pending',
                'payment_type' => 'full',
                'send_email' => false,
            ])->assertCreated();

        $booking = Booking::findOrFail($response->json('data.id'));
        $this->assertSame([], app(BookingDocumentService::class)->missingRequirements($booking));

        // ลบกลุ่มทิ้งแล้ว ไฟล์ที่ใบจองถืออยู่ต้องไม่หายตาม
        $intake->people()->get()->each->delete();
        $disk->assertExists('booking-documents/intake/front.jpg');
    }
}
