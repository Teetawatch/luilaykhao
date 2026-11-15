<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingDocumentService;
use App\Services\FormFillExportService;
use App\Services\PermitFormService;
use App\Services\PermitSignatureService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หน้า "เอกสาร" ของรอบ → data.json ให้สคริปต์ Playwright กรอกฟอร์มออนไลน์ทีละคน
 */
class FormFillExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake(MediaDisk::slipDisk());
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    /** @return array{0: TripSchedule, 1: Booking} */
    private function round(?PermitForm $form = null): array
    {
        $trip = Trip::create([
            'title' => 'เขาช้างเผือก', 'slug' => 'kcp-'.uniqid(), 'type' => 'trekking',
            'location' => 'กาญจนบุรี', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3900, 'status' => 'active',
            'permit_form_id' => $form?->id,
            'document_requirements' => [
                ['key' => 'id-copy', 'label' => 'สำเนาบัตรประชาชน', 'note' => '', 'required' => true],
            ],
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2027-01-10',
            'return_date' => '2027-01-12',
            'total_seats' => 12, 'booked_seats' => 0, 'status' => 'open', 'transport_type' => 'van',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 7800, 'paid_amount' => 7800, 'payment_type' => 'full',
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'นาย', 'name' => 'สมชาย ใจดีมาก',
            'birth_date' => '1994-02-14', 'id_card' => '1-1037-00123-45-6',
            'phone' => '081-234-5678', 'emergency_phone' => '089 876 5432',
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'นางสาว', 'name' => 'สมหญิง ใจดี',
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'นาย', 'name' => 'ไม่ไป แล้ว'])
            ->forceFill(['not_going_at' => now()])->save();

        return [$schedule, $booking->fresh('passengers')];
    }

    /** @return array<string, string> ชื่อไฟล์ใน ZIP → เนื้อไฟล์ */
    private function unzip(string $path): array
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        // เทสต์ไม่ได้ส่ง response จริง deleteFileAfterSend จึงไม่ทำงาน — ลบเอง
        @unlink($path);
        ksort($files);

        return $files;
    }

    private function form(): PermitForm
    {
        $form = app(PermitFormService::class)->create(
            'เขาช้างเผือก',
            [UploadedFile::fake()->image('page.jpg', 420, 594)],
            [['width_mm' => 210, 'height_mm' => 297]],
        );
        $form->update(['fields' => [
            ['id' => 'a', 'page' => 0, 'source' => 'first_name', 'x' => 0.4, 'y' => 0.2, 'w' => 0.2, 'h' => 0.02, 'size' => 11, 'align' => 'left'],
            ['id' => 's', 'page' => 0, 'source' => 'signature', 'x' => 0.55, 'y' => 0.7, 'w' => 0.3, 'h' => 0.05, 'size' => 11, 'align' => 'center'],
        ]]);

        return $form->fresh();
    }

    public function test_default_json_has_one_row_per_traveller_in_the_agreed_shape(): void
    {
        [$schedule] = $this->round();

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/form-fill?format=json")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json();

        $this->assertCount(2, $rows, 'คนที่แจ้งว่าไม่ไปไม่อยู่ในไฟล์');
        $this->assertSame([
            'id' => 1,
            'name' => 'สมชาย ใจดีมาก',
            'bookingRef' => $rows[0]['bookingRef'],
            'birthDate' => '1994-02-14',
            'textFields' => [
                ['keyword' => 'ชื่อจริง', 'value' => 'สมชาย', 'exclude' => ['ฉุกเฉิน', 'ผู้ติดต่อ']],
                ['keyword' => 'นามสกุล', 'value' => 'ใจดีมาก', 'exclude' => ['ชื่อ-นามสกุล', 'ชื่อ - นามสกุล', 'ชื่อและนามสกุล', 'ฉุกเฉิน', 'ผู้ติดต่อ', 'อังกฤษ']],
                ['keyword' => 'อายุ', 'value' => '32', 'exclude' => ['หมดอายุ', 'บัตร']],
                ['keyword' => 'เลขบัตรประชาชน', 'value' => '1103700123456'],
            ],
            'phoneFields' => ['applicantPhone' => '0812345678', 'emergencyPhone' => '0898765432'],
            'choiceFields' => [
                ['keyword' => 'คำนำหน้า', 'value' => 'นาย', 'exclude' => ['ฉุกเฉิน', 'อังกฤษ']],
                ['keyword' => 'เพศ', 'value' => 'ชาย'],
            ],
            'idCardFilePath' => null,
            'permitFilePath' => null,
            'permitSigned' => null,
        ], $rows[0]);

        // ติ๊กไว้แต่ไม่มีในระบบ = null / "" (สคริปต์เตือนให้กรอกเอง)
        $this->assertNull($rows[1]['birthDate']);
        $this->assertSame('', $rows[1]['phoneFields']['applicantPhone']);
        $this->assertSame('หญิง', $rows[1]['choiceFields'][1]['value']);
    }

    public function test_unticked_fields_are_left_out_entirely(): void
    {
        [$schedule] = $this->round();

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/form-fill?format=json&fields=first_name,nickname,title,bogus")
            ->assertOk()
            ->json();

        $this->assertSame(['id', 'name', 'bookingRef', 'textFields', 'phoneFields', 'choiceFields'], array_keys($rows[0]));
        $this->assertSame(['ชื่อจริง', 'ชื่อเล่น'], array_column($rows[0]['textFields'], 'keyword'));
        $this->assertSame(['คำนำหน้า'], array_column($rows[0]['choiceFields'], 'keyword'));
        // ไม่มีเบอร์ที่เลือก = object ว่าง ไม่ใช่ array (สคริปต์อ่านแบบ object)
        $this->assertStringContainsString('"phoneFields":{}', $this->actingAs($this->admin, 'sanctum')
            ->get("/api/v1/admin/calendar/schedules/{$schedule->id}/form-fill?format=json&fields=first_name")
            ->getContent());
    }

    public function test_emergency_relation_uses_the_picked_value_or_a_guess_from_old_bookings(): void
    {
        [$schedule, $booking] = $this->round();
        [$a, $b] = $booking->passengers->all();
        $a->update(['emergency_contact' => 'สมศรี ใจดี', 'emergency_relation' => 'แม่']);
        // ใบจองก่อนมีช่องแยก — ลูกค้าเขียนความสัมพันธ์ไว้ในช่องชื่อ
        $b->update(['emergency_contact' => 'ต้น (แฟน)', 'emergency_relation' => null]);

        $rows = app(FormFillExportService::class)->rows($schedule, 'emergency_name,emergency_relation');

        $this->assertSame(['ผู้ติดต่อฉุกเฉิน', 'ความสัมพันธ์'], array_column($rows[0]['textFields'], 'keyword'));
        $this->assertSame(['สมศรี ใจดี', 'แม่'], array_column($rows[0]['textFields'], 'value'));
        $this->assertSame('แฟน', $rows[1]['textFields'][1]['value']);
        // "ชื่อ-สกุล บุคคลที่ติดต่อได้ในฉุกเฉิน" ไม่มีคำว่า "ผู้ติดต่อฉุกเฉิน" — หาด้วยคำไหนก็ได้ + ต้องมี "ฉุกเฉิน"
        $this->assertContains('ติดต่อ', $rows[0]['textFields'][0]['keywords']);
        $this->assertSame(['ฉุกเฉิน'], $rows[0]['textFields'][0]['require']);
        $this->assertContains('ความสัมพันธ์', $rows[0]['textFields'][0]['exclude']);
    }

    public function test_email_falls_back_to_the_travellers_own_account_but_never_a_line_placeholder(): void
    {
        [$schedule, $booking] = $this->round();
        [$a, $b] = $booking->passengers->all();
        // ที่นั่งของคนจองเอง (เบอร์ตรงกับบัญชี) — ใบจองไม่ได้กรอกอีเมลไว้
        $booking->user->update(['phone' => '0812345678', 'email' => 'somchai@example.com']);
        // คนที่สองผูกบัญชีที่เข้าผ่าน LINE — ไม่มีอีเมลจริง
        $line = User::factory()->create(['email' => 'line_U123@social.local']);
        $booking->members()->create(['user_id' => $line->id, 'passenger_id' => $b->id, 'role' => 'member', 'status' => 'active']);

        $rows = app(FormFillExportService::class)->rows($schedule, 'email');

        $this->assertSame('somchai@example.com', $rows[0]['textFields'][0]['value']);
        $this->assertSame('', $rows[1]['textFields'][0]['value'], 'ไม่มีอีเมลจริง = ว่าง ให้สคริปต์เตือนกรอกเอง');

        // อีเมลที่กรอกในใบจองมาก่อนบัญชี
        $a->update(['email' => 'trip@example.com']);
        $this->assertSame('trip@example.com', app(FormFillExportService::class)->rows($schedule, 'email')[0]['textFields'][0]['value']);
    }

    public function test_blank_health_answers_say_none(): void
    {
        [$schedule, $booking] = $this->round();
        $booking->passengers->first()->update(['health_notes' => 'หอบหืด']);

        $rows = app(FormFillExportService::class)->rows($schedule, 'health_notes,allergies');

        $this->assertSame(['หอบหืด', 'ไม่มี'], array_column($rows[0]['textFields'], 'value'));
        $this->assertSame(['ไม่มี', 'ไม่มี'], array_column($rows[1]['textFields'], 'value'));
        $this->assertContains('โรคประจําตัว', $rows[0]['textFields'][0]['keywords'], 'สระอำแบบนิคหิต+สระอา');
    }

    public function test_booking_stores_the_emergency_relation(): void
    {
        [, $booking] = $this->round();
        $passenger = $booking->passengers->first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", ['passengers' => [[
                'id' => $passenger->id, 'name' => $passenger->name, 'emergency_relation' => 'สามี',
            ]]])
            ->assertOk();
        $this->assertSame('สามี', $passenger->fresh()->emergency_relation);

        // หน้าแอดมินรุ่นที่ไม่ส่งช่องนี้ ต้องไม่ล้างค่าเดิม
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", ['passengers' => [[
                'id' => $passenger->id, 'name' => $passenger->name,
            ]]])
            ->assertOk();
        $this->assertSame('สามี', $passenger->fresh()->emergency_relation);
    }

    public function test_nothing_ticked_is_refused(): void
    {
        [$schedule] = $this->round();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/form-fill?fields=bogus")
            ->assertStatus(422);
    }

    public function test_options_list_every_config_field_with_defaults(): void
    {
        $groups = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/form-fill/options')
            ->assertOk()
            ->json('data.groups');

        $this->assertSame(['text_fields', 'phone_fields', 'choice_fields', 'extras'], array_column($groups, 'key'));
        $items = collect($groups)->flatMap(fn ($g) => $g['items']);
        $this->assertSame($items->count(), $items->pluck('id')->unique()->count(), 'รหัสช่องใน config ห้ามซ้ำกันข้ามกลุ่ม');
        $this->assertTrue($items->firstWhere('id', 'first_name')['default']);
        $this->assertFalse($items->firstWhere('id', 'nickname')['default']);
        $this->assertSame('ชื่อจริง', $items->firstWhere('id', 'first_name')['keyword']);
    }

    public function test_config_controls_questions_static_values_and_option_mapping(): void
    {
        [$schedule] = $this->round();
        config(['form_fill.choice_fields' => [
            'title' => ['label' => 'คำนำหน้า', 'keyword' => 'คำนำหน้าชื่อ', 'source' => 'title', 'map' => ['นางสาว' => 'น.ส.'], 'default' => true],
            'consent' => ['label' => 'ยอมรับ', 'keyword' => 'ยอมรับเงื่อนไข', 'value' => 'ยอมรับ', 'default' => true],
        ]]);

        $rows = app(FormFillExportService::class)->rows($schedule);

        $this->assertSame([
            ['keyword' => 'คำนำหน้าชื่อ', 'value' => 'น.ส.'],
            ['keyword' => 'ยอมรับเงื่อนไข', 'value' => 'ยอมรับ'],
        ], $rows[1]['choiceFields']);
    }

    public function test_zip_has_a_folder_per_person_plus_the_whole_round(): void
    {
        $form = $this->form();
        [$schedule, $booking] = $this->round($form);
        [$a] = $booking->passengers->all();
        app(BookingDocumentService::class)->store($booking, $a, 'id-copy', UploadedFile::fake()->image('card.png'), null);
        app(PermitSignatureService::class)->sign($a, $form, $this->signature(), ['channel' => 'app']);

        $files = $this->unzip(app(FormFillExportService::class)->zip($schedule));

        $this->assertSame([
            '01-สมชาย ใจดีมาก/README.txt',
            '01-สมชาย ใจดีมาก/autofill.js',
            '01-สมชาย ใจดีมาก/data.json',
            '01-สมชาย ใจดีมาก/id_card.png',
            '01-สมชาย ใจดีมาก/permit.pdf',
            '02-สมหญิง ใจดี/README.txt',
            '02-สมหญิง ใจดี/autofill.js',
            '02-สมหญิง ใจดี/data.json',
            '02-สมหญิง ใจดี/permit.pdf',
            'README.txt',
            'autofill.js',
            'data.json',
        ], array_keys($files));

        // ทั้งรอบชี้ไฟล์เข้าไปในโฟลเดอร์ · ในโฟลเดอร์ชี้ไฟล์ข้างตัว
        $round = json_decode($files['data.json'], true);
        $this->assertSame('./01-สมชาย ใจดีมาก/id_card.png', $round[0]['idCardFilePath']);
        $this->assertSame('./01-สมชาย ใจดีมาก/permit.pdf', $round[0]['permitFilePath']);
        $this->assertTrue($round[0]['permitSigned']);
        $this->assertNull($round[1]['idCardFilePath']);
        $this->assertFalse($round[1]['permitSigned']);

        $own = json_decode($files['01-สมชาย ใจดีมาก/data.json'], true);
        $this->assertCount(1, $own);
        $this->assertSame(1, $own[0]['id']);
        $this->assertSame('./id_card.png', $own[0]['idCardFilePath']);
        $this->assertSame('./permit.pdf', $own[0]['permitFilePath']);

        $this->assertStringStartsWith('%PDF', $files['02-สมหญิง ใจดี/permit.pdf']);
        $this->assertStringContainsString('connectOverCDP', $files['autofill.js']);
        // โฟลเดอร์คนรันเองได้ — สคริปต์ชุดเดียวกับชั้นบนสุด
        $this->assertSame($files['autofill.js'], $files['02-สมหญิง ใจดี/autofill.js']);
    }

    /** แอป Flutter ส่งทุกไฟล์เป็น application/octet-stream — รูปบัตรจากแอปต้องยังมาใน ZIP */
    public function test_id_card_uploaded_from_the_app_is_packed(): void
    {
        [$schedule, $booking] = $this->round();
        [$a, $b] = $booking->passengers->all();

        // ไฟล์จริงแต่ชนิดที่ client บอกคือ octet-stream — ตอนนี้ store() อ่านจากเนื้อไฟล์
        $png = UploadedFile::fake()->image('card.png');
        $fromApp = new UploadedFile($png->getRealPath(), 'image_picker_123.png', 'application/octet-stream', null, true);
        $stored = app(BookingDocumentService::class)->store($booking, $a, 'id-copy', $fromApp, null);
        $this->assertSame('image/png', $stored->mime_type);

        // แถวเก่าที่บันทึกเป็น octet-stream ไปแล้ว — ดูจากนามสกุลไฟล์บนดิสก์แทน
        Storage::disk(MediaDisk::slipDisk())->put('booking-documents/2026/11/old.jpg', 'jpeg-bytes');
        $booking->documents()->create([
            'booking_passenger_id' => $b->id, 'requirement_key' => 'id-copy', 'label' => 'สำเนาบัตรประชาชน',
            'file_path' => 'booking-documents/2026/11/old.jpg', 'original_name' => 'image_picker_456',
            'mime_type' => 'application/octet-stream', 'size' => 10,
        ]);

        $files = $this->unzip(app(FormFillExportService::class)->zip($schedule, 'first_name,id_card_file'));

        $this->assertArrayHasKey('01-สมชาย ใจดีมาก/id_card.png', $files);
        $this->assertSame('jpeg-bytes', $files['02-สมหญิง ใจดี/id_card.jpg']);
        $round = json_decode($files['data.json'], true);
        $this->assertSame('./02-สมหญิง ใจดี/id_card.jpg', $round[1]['idCardFilePath']);
    }

    public function test_unticked_files_are_not_packed(): void
    {
        $form = $this->form();
        [$schedule, $booking] = $this->round($form);
        app(BookingDocumentService::class)->store($booking, $booking->passengers->first(), 'id-copy', UploadedFile::fake()->image('card.jpg'), null);

        $files = $this->unzip(app(FormFillExportService::class)->zip($schedule, 'first_name'));

        $this->assertSame([
            '01-สมชาย ใจดีมาก/README.txt',
            '01-สมชาย ใจดีมาก/autofill.js',
            '01-สมชาย ใจดีมาก/data.json',
            '02-สมหญิง ใจดี/README.txt',
            '02-สมหญิง ใจดี/autofill.js',
            '02-สมหญิง ใจดี/data.json',
            'README.txt',
            'autofill.js',
            'data.json',
        ], array_keys($files));
        $this->assertArrayNotHasKey('idCardFilePath', json_decode($files['data.json'], true)[0]);
    }

    public function test_folder_names_are_safe_on_windows(): void
    {
        [$schedule, $booking] = $this->round();
        $booking->passengers->first()->update(['name' => 'ก.เก้ง: "แม่"/ลูก... ']);

        $files = $this->unzip(app(FormFillExportService::class)->zip($schedule, 'first_name'));

        $this->assertArrayHasKey('01-ก.เก้ง แม่ ลูก/data.json', $files);
    }

    private function signature(): string
    {
        $img = imagecreatetruecolor(400, 160);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        imagesetthickness($img, 3);
        imageline($img, 60, 120, 300, 40, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_customers_cannot_export(): void
    {
        [$schedule] = $this->round();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/form-fill?format=json")
            ->assertForbidden();
    }
}
