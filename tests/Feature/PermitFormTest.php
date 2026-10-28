<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\PermitFormFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แบบฟอร์มขออนุญาตของอุทยาน — อัปโหลดครั้งเดียว วางช่อง ผูกทริป แล้วโหลด PDF
 * ที่เติมข้อมูลผู้เดินทางทั้งรอบ (หนึ่งชุดต่อคน)
 */
class PermitFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เขาช้างเผือก', 'slug' => 'kcp-'.uniqid(), 'type' => 'trekking',
            'location' => 'กาญจนบุรี', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3900, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-12-12',
            'return_date' => '2026-12-14',
            'total_seats' => 12, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function book(TripSchedule $schedule, array $passengers, string $status = 'confirmed'): Booking
    {
        $customer = User::factory()->create(['phone' => '0899999999']);
        $customer->assignRole('customer');

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 3900,
            'paid_amount' => 3900,
            'payment_type' => 'full',
        ]);

        foreach ($passengers as $p) {
            // not_going_at ไม่อยู่ใน $fillable (แก้ผ่าน TripAttendanceService เท่านั้น)
            (new BookingPassenger)->forceFill(['booking_id' => $booking->id, ...$p])->save();
        }

        return $booking;
    }

    private function upload(int $pages = 1): PermitForm
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/permit-forms', [
            'name' => 'เขาช้างเผือก 2570',
            'source' => UploadedFile::fake()->create('form.pdf', 50, 'application/pdf'),
            'pages' => array_map(fn () => UploadedFile::fake()->image('page.jpg', 420, 594), range(1, $pages)),
            'page_sizes' => array_fill(0, $pages, ['width_mm' => 210, 'height_mm' => 297]),
        ])->assertCreated();

        return PermitForm::latest('id')->first();
    }

    private function fields(): array
    {
        return [
            ['id' => 'a', 'page' => 0, 'source' => 'first_name', 'x' => 0.4, 'y' => 0.2, 'w' => 0.2, 'h' => 0.02, 'size' => 11],
            ['id' => 'b', 'page' => 0, 'source' => 'check_mr', 'x' => 0.27, 'y' => 0.2, 'w' => 0.02, 'h' => 0.02],
            ['id' => 'c', 'page' => 0, 'source' => 'static', 'x' => 0.5, 'y' => 0.6, 'w' => 0.2, 'h' => 0.02, 'text' => 'วิริยะประกันภัย'],
        ];
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_admin_uploads_a_form_and_its_pages_are_stored_privately(): void
    {
        $form = $this->upload(2);

        $this->assertSame('เขาช้างเผือก 2570', $form->name);
        $this->assertCount(2, $form->pages);
        $this->assertSame(210.0, (float) $form->pages[0]['width_mm']);
        Storage::disk('local')->assertExists($form->pages[0]['path']);
        Storage::disk('local')->assertExists($form->source_path);

        $this->actingAs($this->admin)->get("/api/v1/admin/permit-forms/{$form->id}/pages/2")->assertOk();
        $this->actingAs($this->admin)->get("/api/v1/admin/permit-forms/{$form->id}/pages/3")->assertNotFound();
    }

    public function test_customers_cannot_reach_permit_forms(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)->getJson('/api/v1/admin/permit-forms')->assertForbidden();
    }

    public function test_saving_fields_drops_unknown_sources_and_clamps_positions(): void
    {
        $form = $this->upload();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/permit-forms/{$form->id}", [
            'fields' => [
                ...$this->fields(),
                ['id' => 'x', 'page' => 0, 'source' => 'password', 'x' => 0.1, 'y' => 0.1],
                ['id' => 'y', 'page' => 4, 'source' => 'last_name', 'x' => 0.1, 'y' => 0.1],
                ['id' => 'z', 'page' => 0, 'source' => 'last_name', 'x' => 1.7, 'y' => -2, 'w' => 5, 'size' => 200],
            ],
        ])->assertOk();

        $fields = collect($form->fresh()->fields)->keyBy('id');
        $this->assertSame(['a', 'b', 'c', 'z'], $fields->keys()->all());
        $this->assertSame(0.99, $fields['z']['x']);
        $this->assertSame(0.0, (float) $fields['z']['y']);
        $this->assertEqualsWithDelta(0.01, $fields['z']['w'], 0.00001);
        $this->assertSame(36.0, (float) $fields['z']['size']);
        $this->assertSame('วิริยะประกันภัย', $fields['c']['text']);
        $this->assertArrayNotHasKey('text', $fields['a']);
    }

    public function test_ticking_trips_links_them_and_moves_them_off_other_forms(): void
    {
        $first = $this->upload();
        $second = $this->upload();
        $a = $this->makeSchedule()->trip;
        $b = $this->makeSchedule()->trip;

        $this->actingAs($this->admin)->putJson("/api/v1/admin/permit-forms/{$first->id}", ['trip_ids' => [$a->id, $b->id]])->assertOk();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/permit-forms/{$second->id}", ['trip_ids' => [$b->id]])->assertOk();

        $this->assertSame($first->id, $a->fresh()->permit_form_id);
        $this->assertSame($second->id, $b->fresh()->permit_form_id);

        $this->actingAs($this->admin)->putJson("/api/v1/admin/permit-forms/{$first->id}", ['trip_ids' => []])->assertOk();
        $this->assertNull($a->fresh()->permit_form_id);
    }

    public function test_round_pdf_has_one_copy_per_traveller_still_going(): void
    {
        $form = $this->upload(2);
        $form->update(['fields' => $this->fields()]);
        $schedule = $this->makeSchedule();
        $schedule->trip->update(['permit_form_id' => $form->id]);

        $this->book($schedule, [
            ['title' => 'นาย', 'name' => 'สมชาย ใจดีมาก'],
            ['title' => 'นางสาว', 'name' => 'สมหญิง ใจดีมาก'],
            ['title' => 'นาย', 'name' => 'ไม่ไป แล้ว', 'not_going_at' => now()],
        ]);
        $this->book($schedule, [['title' => 'นาง', 'name' => 'ยกเลิก แล้ว']], 'cancelled');

        $response = $this->actingAs($this->admin)->get("/api/v1/admin/schedules/{$schedule->id}/permit-forms");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        // 2 คนที่ยังไป × ฟอร์ม 2 หน้า
        $this->assertSame(4, $this->pageCount($response->getContent()));
    }

    public function test_booking_pdf_covers_only_that_booking(): void
    {
        $form = $this->upload();
        $form->update(['fields' => $this->fields()]);
        $schedule = $this->makeSchedule();
        $schedule->trip->update(['permit_form_id' => $form->id]);

        $booking = $this->book($schedule, [['name' => 'ก ข'], ['name' => 'ค ง'], ['name' => 'จ ฉ']]);
        $this->book($schedule, [['name' => 'คนอื่น ในรอบ']]);

        $response = $this->actingAs($this->admin)->get("/api/v1/admin/bookings/{$booking->booking_ref}/permit-form");

        $response->assertOk();
        $this->assertSame(3, $this->pageCount($response->getContent()));
    }

    public function test_trip_without_a_form_says_so(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->book($schedule, [['name' => 'ก ข']]);

        $this->actingAs($this->admin)->getJson("/api/v1/admin/schedules/{$schedule->id}/permit-forms")->assertStatus(422);
        $this->actingAs($this->admin)->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/permit-form")->assertStatus(422);
    }

    public function test_preview_renders_unsaved_fields_with_sample_data(): void
    {
        $form = $this->upload();

        $response = $this->actingAs($this->admin)->post("/api/v1/admin/permit-forms/{$form->id}/preview", [
            'fields' => $this->fields(),
        ]);

        $response->assertOk();
        $this->assertSame(1, $this->pageCount($response->getContent()));
        $this->assertSame([], $form->fresh()->fields, 'preview must not save');
    }

    public function test_manifest_and_admin_booking_say_which_form_applies(): void
    {
        $form = $this->upload();
        $schedule = $this->makeSchedule();
        $schedule->trip->update(['permit_form_id' => $form->id]);
        $booking = $this->book($schedule, [['name' => 'ก ข']]);

        $this->actingAs($this->admin)->getJson("/api/v1/admin/schedules/{$schedule->id}/manifest")
            ->assertJsonPath('data.permit_form.id', $form->id);
        $this->actingAs($this->admin)->getJson("/api/v1/admin/bookings/{$booking->booking_ref}")
            ->assertJsonPath('data.permit_form_id', $form->id);
    }

    public function test_deleting_a_form_unlinks_trips_and_removes_files(): void
    {
        $form = $this->upload();
        $trip = $this->makeSchedule()->trip;
        $trip->update(['permit_form_id' => $form->id]);
        $path = $form->pages[0]['path'];

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/permit-forms/{$form->id}")->assertOk();

        $this->assertNull($trip->fresh()->permit_form_id);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_values_fill_what_we_know_and_leave_the_rest_blank(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->book($schedule, [[
            'title' => 'นางสาว', 'name' => 'สมหญิง ณ  อยุธยา', 'birth_date' => '1994-12-13',
            'id_card' => '1-1037-00123-45-6', 'nationality' => 'TH', 'blood_group' => 'O',
            'emergency_contact' => 'สมชาย ใจดี', 'emergency_phone' => '0811111111',
        ]]);
        $passenger = $booking->passengers()->first();

        $v = PermitFormFields::values($passenger, $schedule->fresh('trip'), 7, Carbon::parse('2026-10-28'));

        $this->assertSame('สมหญิง', $v['first_name']);
        $this->assertSame('ณ  อยุธยา', $v['last_name']);
        $this->assertSame('นางสาวสมหญิง ณ  อยุธยา', $v['title_full_name']);
        $this->assertSame('31', $v['age'], 'age on departure day, birthday is the day after');
        $this->assertSame('1103700123456', $v['id_card']);
        $this->assertSame('ไทย', $v['nationality']);
        $this->assertSame('', $v['check_mr']);
        $this->assertSame('1', $v['check_miss']);
        $this->assertSame('สมชาย', $v['emergency_first_name']);
        $this->assertSame('ใจดี', $v['emergency_last_name']);
        $this->assertSame('3', $v['days_count']);
        $this->assertSame(['12', 'ธันวาคม', '2569'], [$v['start_day'], $v['start_month'], $v['start_year']]);
        $this->assertSame(['14', 'ธันวาคม', '2569'], [$v['end_day'], $v['end_month'], $v['end_year']]);
        $this->assertSame(['28', 'ตุลาคม', '2569'], [$v['today_day'], $v['today_month'], $v['today_year']]);
        $this->assertSame('7', $v['group_size']);
        $this->assertSame('', $v['health_notes']);
        $this->assertSame('0899999999', $v['phone'], 'falls back to the account phone');
    }
}
