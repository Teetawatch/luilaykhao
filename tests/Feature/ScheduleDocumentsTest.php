<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingDocumentService;
use App\Services\PermitFormService;
use App\Services\PermitSignatureService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หน้าตารางที่นั่งว่าง → "เอกสาร" ของรอบ: ใครยังไม่เซ็นแบบฟอร์มอุทยาน + ลิงก์ส่งต่อ,
 * ไฟล์ที่ลูกค้าแนบ และ ZIP ทั้งรอบ/รายคน
 */
class ScheduleDocumentsTest extends TestCase
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

    /** @return array{0: TripSchedule, 1: Booking} */
    private function round(?PermitForm $form, array $names = ['สมชาย ใจดี', 'สมหญิง ใจดี']): array
    {
        $trip = Trip::create([
            'title' => 'เขาช้างเผือก', 'slug' => 'kcp-'.uniqid(), 'type' => 'trekking',
            'location' => 'กาญจนบุรี', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3900, 'status' => 'active',
            'permit_form_id' => $form?->id,
            'document_requirements' => [
                ['key' => 'medical', 'label' => 'ใบรับรองแพทย์', 'note' => 'ยื่นอุทยาน', 'required' => true],
            ],
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(22)->toDateString(),
            'total_seats' => 12, 'booked_seats' => 0, 'status' => 'open', 'transport_type' => 'van',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3900, 'paid_amount' => 3900, 'payment_type' => 'full',
            // แจ้งให้เซ็นไปแล้ว — เทสต์นี้ไม่สนใจข้อความแรก
            'permit_requested_at' => now()->subDay(),
        ]);

        foreach ($names as $name) {
            BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'นาย', 'name' => $name, 'id_card' => '1103700123456']);
        }

        return [$schedule, $booking->fresh('passengers')];
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

    private function zipEntries(string $path): array
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        // เทสต์ไม่ได้ส่ง response จริง deleteFileAfterSend จึงไม่ทำงาน — ลบเอง
        @unlink($path);
        sort($names);

        return $names;
    }

    public function test_admin_sees_who_still_has_to_sign_with_a_link_and_their_files(): void
    {
        $form = $this->form();
        [$schedule, $booking] = $this->round($form);
        [$a, $b] = $booking->passengers->all();

        app(PermitSignatureService::class)->sign($a, $form, $this->signature(), ['channel' => 'app']);
        app(BookingDocumentService::class)->store($booking, $b, 'medical', UploadedFile::fake()->image('med.jpg'), null);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.permit_form.name', 'เขาช้างเผือก')
            ->assertJsonPath('data.signed_count', 1)
            ->assertJsonPath('data.unsigned_count', 1)
            ->assertJsonPath('data.missing_documents_count', 1)
            ->assertJsonPath('data.file_count', 1)
            ->assertJsonPath('data.passengers.0.permit_signed', true)
            ->assertJsonPath('data.passengers.0.sign_url', null)
            ->assertJsonPath('data.passengers.0.missing_labels', ['ใบรับรองแพทย์'])
            ->assertJsonPath('data.passengers.1.permit_signed', false)
            ->assertJsonPath('data.passengers.1.documents.0.files.0.original_name', 'med.jpg');

        $this->assertStringContainsString('/f/'.$b->fresh()->pass_token.'/permit', $res->json('data.passengers.1.sign_url'));
        $this->assertStringContainsString($res->json('data.passengers.1.sign_url'), $res->json('data.passengers.1.share_text'));
    }

    public function test_calendar_flags_the_round_and_counts_unsigned_people(): void
    {
        [$schedule] = $this->round($this->form());

        $event = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk()
            ->json('data'))->firstWhere('id', $schedule->id);

        $this->assertSame('เขาช้างเผือก', $event['permit_form_name']);
        $this->assertSame(2, $event['permit_unsigned_count']);
        $this->assertSame(1, $event['document_requirement_count']);
    }

    public function test_round_zip_has_a_folder_per_person_with_the_form_and_files(): void
    {
        $form = $this->form();
        [$schedule, $booking] = $this->round($form);
        [$a, $b] = $booking->passengers->all();

        app(PermitSignatureService::class)->sign($a, $form, $this->signature(), ['channel' => 'app']);
        app(BookingDocumentService::class)->store($booking, $b, 'medical', UploadedFile::fake()->image('med.jpg'), null);
        app(BookingDocumentService::class)->store($booking, $b, 'medical', UploadedFile::fake()->create('med2.pdf', 10, 'application/pdf'), null);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/v1/admin/calendar/schedules/{$schedule->id}/documents/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertSame([
            '01-สมชาย ใจดี/แบบฟอร์มอุทยาน-เขาช้างเผือก.pdf',
            '02-สมหญิง ใจดี/แบบฟอร์มอุทยาน-เขาช้างเผือก (ยังไม่เซ็น).pdf',
            '02-สมหญิง ใจดี/ใบรับรองแพทย์-1.jpg',
            '02-สมหญิง ใจดี/ใบรับรองแพทย์-2.pdf',
        ], $this->zipEntries($res->baseResponse->getFile()->getPathname()));
    }

    public function test_one_person_zip_holds_only_their_files(): void
    {
        $form = $this->form();
        [$schedule, $booking] = $this->round($form);
        $b = $booking->passengers[1];
        app(BookingDocumentService::class)->store($booking, $b, 'medical', UploadedFile::fake()->image('med.jpg'), null);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/v1/admin/calendar/schedules/{$schedule->id}/documents/download?passenger_id={$b->id}")
            ->assertOk();

        $this->assertSame([
            'แบบฟอร์มอุทยาน-เขาช้างเผือก (ยังไม่เซ็น).pdf',
            'ใบรับรองแพทย์.jpg',
        ], $this->zipEntries($res->baseResponse->getFile()->getPathname()));

        // คนจากรอบอื่นโหลดผ่านรอบนี้ไม่ได้
        [, $other] = $this->round($form);
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/documents/download?passenger_id={$other->passengers[0]->id}")
            ->assertNotFound();
    }

    public function test_nothing_to_download_and_customers_are_forbidden(): void
    {
        [$schedule, $booking] = $this->round(null);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/documents/download")
            ->assertStatus(422);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/documents")
            ->assertForbidden();
    }
}
