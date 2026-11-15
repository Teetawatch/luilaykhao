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

    public function test_json_has_one_row_per_traveller_in_the_configured_shape(): void
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
            'bookingRef' => $rows[0]['bookingRef'],
            'birthDate' => '1994-02-14',
            'textFields' => [
                ['keyword' => 'ชื่อจริง', 'value' => 'สมชาย'],
                ['keyword' => 'นามสกุล', 'value' => 'ใจดีมาก'],
                ['keyword' => 'อายุ', 'value' => '32'],
                ['keyword' => 'เลขบัตรประชาชน', 'value' => '1103700123456'],
            ],
            'phoneFields' => ['applicantPhone' => '0812345678', 'emergencyPhone' => '0898765432'],
            'choiceFields' => [
                ['keyword' => 'คำนำหน้า', 'value' => 'นาย'],
                ['keyword' => 'เพศ', 'value' => 'ชาย'],
            ],
            'idCardFilePath' => null,
            'permitFilePath' => null,
            'permitSigned' => null,
        ], $rows[0]);

        $this->assertNull($rows[1]['birthDate']);
        $this->assertSame('หญิง', $rows[1]['choiceFields'][1]['value']);
    }

    public function test_config_controls_questions_static_values_and_option_mapping(): void
    {
        [$schedule] = $this->round();
        config(['form_fill.choice_fields' => [
            ['keyword' => 'คำนำหน้าชื่อ', 'source' => 'title', 'map' => ['นางสาว' => 'น.ส.']],
            ['keyword' => 'ยอมรับเงื่อนไข', 'value' => 'ยอมรับ'],
        ]]);

        $rows = app(FormFillExportService::class)->rows($schedule);

        $this->assertSame([
            ['keyword' => 'คำนำหน้าชื่อ', 'value' => 'น.ส.'],
            ['keyword' => 'ยอมรับเงื่อนไข', 'value' => 'ยอมรับ'],
        ], $rows[1]['choiceFields']);
    }

    public function test_zip_holds_data_json_and_each_id_card_next_to_it(): void
    {
        [$schedule, $booking] = $this->round();
        $first = $booking->passengers->first();
        app(BookingDocumentService::class)->store($booking, $first, 'id-copy', UploadedFile::fake()->image('card.png'), null);

        $path = app(FormFillExportService::class)->zip($schedule);
        $zip = new \ZipArchive;
        $zip->open($path);
        $rows = json_decode($zip->getFromName('data.json'), true);
        $hasImage = $zip->locateName('id_card_1.png') !== false;
        $count = $zip->numFiles;
        $script = $zip->getFromName('autofill.js');
        $readme = $zip->locateName('README.txt') !== false;
        $zip->close();
        @unlink($path);

        $this->assertTrue($hasImage);
        $this->assertSame(4, $count, 'data.json + รูปบัตร 1 ใบ + autofill.js + README.txt');
        $this->assertStringContainsString('connectOverCDP', (string) $script);
        $this->assertTrue($readme);
        $this->assertSame('./id_card_1.png', $rows[0]['idCardFilePath']);
        $this->assertNull($rows[1]['idCardFilePath']);
    }

    public function test_zip_has_each_persons_park_form_and_says_who_has_not_signed(): void
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
        $form = $form->fresh();
        [$schedule, $booking] = $this->round($form);
        app(PermitSignatureService::class)->sign($booking->passengers->first(), $form, $this->signature(), ['channel' => 'app']);

        $path = app(FormFillExportService::class)->zip($schedule);
        $zip = new \ZipArchive;
        $zip->open($path);
        $rows = json_decode($zip->getFromName('data.json'), true);
        $pdfs = [$zip->getFromName('permit_1.pdf'), $zip->getFromName('permit_2.pdf')];
        $zip->close();
        @unlink($path);

        foreach ($pdfs as $pdf) {
            $this->assertStringStartsWith('%PDF', (string) $pdf);
        }
        $this->assertSame(['./permit_1.pdf', true], [$rows[0]['permitFilePath'], $rows[0]['permitSigned']]);
        $this->assertSame(['./permit_2.pdf', false], [$rows[1]['permitFilePath'], $rows[1]['permitSigned']]);
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
