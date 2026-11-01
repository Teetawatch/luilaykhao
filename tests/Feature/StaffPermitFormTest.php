<?php

namespace Tests\Feature;

use App\Jobs\PurgePermitSignaturesJob;
use App\Jobs\RemindPermitSignaturesJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\SmartNotification;
use App\Models\StaffPermitSignature;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PermitFormService;
use App\Services\StaffPermitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แบบฟอร์มอุทยานของสตาฟประจำรอบ — อุทยานลงทะเบียนทีมงานด้วย ข้อมูลมาจากโปรไฟล์
 */
class StaffPermitFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['admin', 'customer', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->staff = User::factory()->create([
            'title' => 'นาย', 'name' => 'วิชัย กล้าหาญ', 'nickname' => null,
            'id_card' => '1103700999999', 'phone' => '0811111111',
        ]);
        $this->staff->assignRole('staff');
    }

    private function form(?int $dueDays = 7): PermitForm
    {
        $form = app(PermitFormService::class)->create(
            'เขาช้างเผือก',
            [UploadedFile::fake()->image('page.jpg', 420, 594)],
            [['width_mm' => 210, 'height_mm' => 297]],
        );
        $form->update([
            'sign_due_days' => $dueDays,
            'fields' => [
                ['id' => 'a', 'page' => 0, 'source' => 'first_name', 'x' => 0.4, 'y' => 0.2, 'w' => 0.2, 'h' => 0.02, 'size' => 11, 'align' => 'left'],
                ['id' => 'b', 'page' => 0, 'source' => 'id_card', 'x' => 0.6, 'y' => 0.25, 'w' => 0.3, 'h' => 0.02, 'size' => 11, 'align' => 'left'],
                ['id' => 'g', 'page' => 0, 'source' => 'group_size', 'x' => 0.6, 'y' => 0.3, 'w' => 0.1, 'h' => 0.02, 'size' => 11, 'align' => 'left'],
                ['id' => 's', 'page' => 0, 'source' => 'signature', 'x' => 0.55, 'y' => 0.7, 'w' => 0.3, 'h' => 0.05, 'size' => 11, 'align' => 'center'],
            ],
        ]);

        return $form->fresh();
    }

    /** รอบที่มีลูกทริปหนึ่งใบ (2 คน) และสตาฟคนหนึ่ง */
    private function round(PermitForm $form, int $daysAhead = 20): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เขาช้างเผือก', 'slug' => 'kcp-'.uniqid(), 'type' => 'trekking',
            'location' => 'กาญจนบุรี', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3900, 'status' => 'active',
            'permit_form_id' => $form->id,
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysAhead)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysAhead + 2)->toDateString(),
            'total_seats' => 12, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
        $schedule->forceFill(['departure_confirmed_at' => now()])->save();

        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3900,
            'paid_amount' => 3900,
            'payment_type' => 'full',
            'permit_requested_at' => now()->subDay(),
            'permit_reminded_at' => now()->subDay(),
        ]);
        foreach (['สมชาย ใจดี', 'สมหญิง ใจดี'] as $name) {
            BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'นาย', 'name' => $name, 'id_card' => '1103700123456']);
        }

        $schedule->staff()->attach($this->staff->id, ['assigned_by' => $this->admin->id]);

        return $schedule->fresh();
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

    private function zipNames(string $path): array
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->all();
        $zip->close();
        @unlink($path);

        return $names;
    }

    public function test_staff_signs_a_form_filled_from_their_profile(): void
    {
        $schedule = $this->round($this->form());

        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/staff/schedules/my')
            ->assertOk()
            ->assertJsonPath('data.schedules.0.permit_form.form_name', 'เขาช้างเผือก')
            ->assertJsonPath('data.schedules.0.permit_form.signed', false);

        $preview = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/permit-form")
            ->assertOk()
            ->assertJsonPath('data.passenger.name', 'วิชัย กล้าหาญ')
            ->assertJsonPath('data.signed', false)
            ->assertJsonPath('data.missing_profile', [])
            ->json('data');

        $values = collect($preview['fields'])->pluck('value')->all();
        $this->assertContains('วิชัย', $values);
        $this->assertContains('1103700999999', $values);
        // กลุ่มคณะนับทีมงานด้วย: ลูกทริป 2 + สตาฟ 1
        $this->assertContains('3', $values);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/permit-form/sign", ['signature' => $this->signature()])
            ->assertStatus(422);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/permit-form/sign", ['signature' => $this->signature(), 'accept' => true])
            ->assertOk()
            ->assertJsonPath('data.signed', true);

        $row = StaffPermitSignature::where('schedule_id', $schedule->id)->where('user_id', $this->staff->id)->first();
        $this->assertSame('app', $row->sign_meta['channel']);
        Storage::disk('local')->assertExists($row->signature_path);

        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/staff/schedules/my')
            ->assertJsonPath('data.schedules.0.permit_form.signed', true);
    }

    public function test_empty_profile_fields_the_form_uses_are_flagged(): void
    {
        $this->staff->update(['id_card' => null]);
        $schedule = $this->round($this->form());

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/permit-form")
            ->assertOk()
            ->assertJsonPath('data.missing_profile', ['เลขบัตรประชาชน']);
    }

    public function test_only_the_rounds_own_staff_can_open_or_sign(): void
    {
        $schedule = $this->round($this->form());
        $other = User::factory()->create();
        $other->assignRole('staff');

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/permit-form")
            ->assertNotFound();
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/permit-form/sign", ['signature' => $this->signature(), 'accept' => true])
            ->assertNotFound();
    }

    public function test_round_pdf_zip_and_documents_include_the_staff(): void
    {
        $form = $this->form();
        $schedule = $this->round($form);

        $res = $this->actingAs($this->admin)->get("/api/v1/admin/schedules/{$schedule->id}/permit-forms?format=zip")->assertOk();
        $this->assertSame([
            '01-สมชาย ใจดี (ยังไม่เซ็น).pdf',
            '02-สมหญิง ใจดี (ยังไม่เซ็น).pdf',
            '03-ทีมงาน-วิชัย กล้าหาญ (ยังไม่เซ็น).pdf',
        ], $this->zipNames($res->baseResponse->getFile()->getPathname()));

        $docs = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.staff.0.user_id', $this->staff->id)
            ->assertJsonPath('data.staff.0.permit_signed', false)
            ->assertJsonPath('data.staff_unsigned_count', 1)
            ->json('data.staff.0');
        $token = StaffPermitSignature::where('user_id', $this->staff->id)->value('token');
        $this->assertStringContainsString('/staff-permit/'.$token, $docs['sign_url']);
        $this->assertStringContainsString($docs['sign_url'], $docs['share_text']);

        $event = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/calendar/schedules')->json('data'))
            ->firstWhere('id', $schedule->id);
        $this->assertSame(3, $event['permit_unsigned_count']);

        $zip = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/v1/admin/calendar/schedules/{$schedule->id}/documents/download")->assertOk();
        $this->assertContains('03-ทีมงาน-วิชัย กล้าหาญ/แบบฟอร์มอุทยาน-เขาช้างเผือก (ยังไม่เซ็น).pdf', $this->zipNames($zip->baseResponse->getFile()->getPathname()));

        $one = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/v1/admin/calendar/schedules/{$schedule->id}/documents/download?staff_user_id={$this->staff->id}")->assertOk();
        $this->assertSame(['แบบฟอร์มอุทยาน-เขาช้างเผือก (ยังไม่เซ็น).pdf'], $this->zipNames($one->baseResponse->getFile()->getPathname()));

        // เซ็นแล้ว — ฟอร์มของสตาฟใน PDF ทั้งรอบมีลายเซ็น และป้าย "ยังไม่เซ็น" หาย
        app(StaffPermitService::class)->sign(StaffPermitSignature::where('user_id', $this->staff->id)->first(), $form, $this->signature(), ['channel' => 'app']);
        $res = $this->actingAs($this->admin)->get("/api/v1/admin/schedules/{$schedule->id}/permit-forms?format=zip");
        $this->assertContains('03-ทีมงาน-วิชัย กล้าหาญ.pdf', $this->zipNames($res->baseResponse->getFile()->getPathname()));
        $pdf = $this->actingAs($this->admin)->get("/api/v1/admin/schedules/{$schedule->id}/permit-forms")->getContent();
        // ภาพพื้นหลังใช้ซ้ำทุกหน้า + ลายเซ็นของสตาฟ (PNG โปร่งใสมาพร้อม SMask)
        $this->assertSame(3, preg_match_all('#/Subtype\s*/Image#', $pdf));
    }

    public function test_staff_link_masks_data_signs_and_closes_when_removed(): void
    {
        $schedule = $this->round($this->form());
        $row = app(StaffPermitService::class)->rowFor($schedule, $this->staff);

        $html = $this->get('/staff-permit/'.$row->token)->assertOk()->getContent();
        $this->assertStringContainsString('วิชัย กล้าหาญ', $html);
        $this->assertStringNotContainsString('1103700999999', $html);
        $this->assertStringContainsString('9999', $html);

        $this->post('/staff-permit/'.$row->token, ['signature' => $this->signature(), 'accept' => '1'])
            ->assertRedirect('/staff-permit/'.$row->token);
        $this->assertSame('staff_link', $row->fresh()->sign_meta['channel']);

        $schedule->staff()->detach($this->staff->id);
        $this->get('/staff-permit/'.$row->token)->assertNotFound();
    }

    public function test_unsigned_staff_are_reminded_once_a_day_and_admin_can_nudge(): void
    {
        $schedule = $this->round($this->form(dueDays: 7), daysAhead: 8);

        app()->call([new RemindPermitSignaturesJob, 'handle']);
        app()->call([new RemindPermitSignaturesJob, 'handle']);

        $sent = SmartNotification::where('user_id', $this->staff->id)->where('type', 'staff_permit_sign')->get();
        $this->assertCount(1, $sent);
        $this->assertSame((string) $schedule->id, $sent->first()->data['schedule_id']);

        $this->travel(1)->days();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/staff/{$this->staff->id}/permit-reminder")
            ->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/staff/{$this->staff->id}/permit-reminder")
            ->assertStatus(422);

        $row = StaffPermitSignature::where('user_id', $this->staff->id)->first();
        $this->assertSame(1, $row->reminder_count);
    }

    public function test_staff_signatures_are_purged_after_the_trip_or_when_removed(): void
    {
        $form = $this->form();
        $past = $this->round($form, daysAhead: -20);
        $upcoming = $this->round($form);
        $removed = $this->round($form);
        $service = app(StaffPermitService::class);

        $paths = [];
        foreach ([$past, $upcoming, $removed] as $schedule) {
            $row = $service->rowFor($schedule, $this->staff);
            $row->forceFill(['signature_path' => 'permit-signatures/s-'.$schedule->id.'.png', 'signed_at' => now(), 'signed_form_id' => $form->id])->save();
            Storage::disk('local')->put($row->signature_path, 'png');
            $paths[$schedule->id] = $row->signature_path;
        }
        $removed->staff()->detach($this->staff->id);

        app()->call([new PurgePermitSignaturesJob, 'handle']);

        $this->assertSame([$upcoming->id], StaffPermitSignature::pluck('schedule_id')->all());
        Storage::disk('local')->assertMissing($paths[$past->id]);
        Storage::disk('local')->assertMissing($paths[$removed->id]);
        Storage::disk('local')->assertExists($paths[$upcoming->id]);
    }

    public function test_admin_clears_a_staff_signature(): void
    {
        $form = $this->form();
        $schedule = $this->round($form);
        $service = app(StaffPermitService::class);
        $row = $service->sign($service->rowFor($schedule, $this->staff), $form, $this->signature(), ['channel' => 'app']);
        $path = $row->signature_path;

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$schedule->id}/staff/{$this->staff->id}/permit-signature")
            ->assertOk();

        $this->assertNull($row->fresh()->signed_at);
        Storage::disk('local')->assertMissing($path);
    }
}
