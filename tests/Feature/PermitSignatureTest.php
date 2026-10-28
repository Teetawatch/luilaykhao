<?php

namespace Tests\Feature;

use App\Jobs\PurgePermitSignaturesJob;
use App\Jobs\RemindPermitSignaturesJob;
use App\Mail\PaymentConfirmedMail;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PermitFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เซ็นแบบฟอร์มอุทยานก่อนลงทะเบียนออนไลน์ — แอป/เว็บ (API) และลิงก์ของเพื่อน
 */
class PermitSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->owner = User::factory()->create(['phone' => '0899999999']);
        $this->owner->assignRole('customer');
    }

    private function form(?int $dueDays = 7, bool $withSignature = true): PermitForm
    {
        $image = UploadedFile::fake()->image('page.jpg', 420, 594);

        $form = app(PermitFormService::class)->create('เขาช้างเผือก', [$image], [['width_mm' => 210, 'height_mm' => 297]]);
        $form->update([
            'sign_due_days' => $dueDays,
            'fields' => array_values(array_filter([
                ['id' => 'a', 'page' => 0, 'source' => 'first_name', 'x' => 0.4, 'y' => 0.2, 'w' => 0.2, 'h' => 0.02, 'size' => 11, 'align' => 'left'],
                ['id' => 'b', 'page' => 0, 'source' => 'id_card', 'x' => 0.6, 'y' => 0.25, 'w' => 0.3, 'h' => 0.02, 'size' => 11, 'align' => 'left'],
                $withSignature
                    ? ['id' => 's', 'page' => 0, 'source' => 'signature', 'x' => 0.55, 'y' => 0.7, 'w' => 0.3, 'h' => 0.05, 'size' => 11, 'align' => 'center']
                    : null,
            ])),
        ]);

        return $form->fresh();
    }

    /**
     * @param  string  $status  'pending' = ยังไม่ยืนยัน (ไว้ทดสอบการแจ้งครั้งแรกตอนยืนยัน)
     * @param  bool  $requested  แจ้งครั้งแรกไปแล้วเมื่อวาน — เทสต์อื่นไม่ต้องสนใจข้อความนั้น
     */
    private function booking(
        PermitForm $form,
        int $daysAhead = 20,
        array $names = ['สมชาย ใจดี', 'สมหญิง ใจดี'],
        string $status = 'confirmed',
        bool $requested = true,
        bool $departureConfirmed = true,
    ): Booking {
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
        if ($departureConfirmed) {
            $schedule->forceFill(['departure_confirmed_at' => now()])->save();
        }

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $this->owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 3900,
            'paid_amount' => 3900,
            'payment_type' => 'full',
        ]);

        foreach ($names as $name) {
            BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'นาย', 'name' => $name, 'id_card' => '1103700123456']);
        }

        if ($requested) {
            $booking->forceFill([
                'permit_requested_at' => now()->subDay(),
                'permit_reminded_at' => now()->subDay(),
            ])->saveQuietly();
        }

        return $booking->fresh('passengers');
    }

    private function signature(bool $blank = false): string
    {
        $img = imagecreatetruecolor(400, 160);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        if (! $blank) {
            imagesetthickness($img, 3);
            imageline($img, 60, 120, 300, 40, imagecolorallocate($img, 0, 0, 0));
        }
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function signUrl(Booking $booking, BookingPassenger $p): string
    {
        return "/api/v1/bookings/{$booking->booking_ref}/permit-form/passengers/{$p->id}/sign";
    }

    public function test_owner_sees_status_and_signs_for_each_traveller(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        [$a, $b] = $booking->passengers->sortBy('id')->values();

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/bookings/{$booking->booking_ref}/permit-form")
            ->assertOk()
            ->assertJsonPath('data.signed_count', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.deadline', now('Asia/Bangkok')->addDays(13)->toDateString())
            ->assertJsonPath('data.viewer_role', 'owner');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->signUrl($booking, $a), ['signature' => $this->signature(), 'accept' => true])
            ->assertOk()
            ->assertJsonPath('data.signed_count', 1);

        $a->refresh();
        $this->assertTrue($a->hasSignedPermitForm($form->id));
        $this->assertSame('app', $a->permit_sign_meta['channel']);
        Storage::disk('local')->assertExists($a->permit_signature_path);
        $this->assertArrayNotHasKey('permit_signature_path', $a->toArray(), 'path never serialized');

        // ตัดขอบโปร่งใส: เส้นยาว 240×80 + ขอบ 6 — ไม่ใช่แผ่น 400×160 เต็ม
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($a->permit_signature_path));
        $this->assertLessThan(300, $w);
        $this->assertLessThan(120, $h);

        $this->assertFalse($b->fresh()->hasSignedPermitForm($form->id));
    }

    public function test_signing_needs_a_drawn_signature_and_consent(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        $p = $booking->passengers->first();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->signUrl($booking, $p), ['signature' => $this->signature()])
            ->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->signUrl($booking, $p), ['signature' => $this->signature(blank: true), 'accept' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ยังไม่ได้เซ็น วาดลายเซ็นในกรอบก่อนกดยืนยัน');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->signUrl($booking, $p), ['signature' => 'data:image/png;base64,'.base64_encode('<svg/>'), 'accept' => true])
            ->assertStatus(422);

        $this->assertNull($p->fresh()->permit_signed_at);
    }

    public function test_a_linked_friend_signs_only_their_own_form(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        [$a, $b] = $booking->passengers->sortBy('id')->values();
        $friend = User::factory()->create();
        BookingMember::create(['booking_id' => $booking->id, 'user_id' => $friend->id, 'passenger_id' => $b->id, 'status' => BookingMember::STATUS_ACTIVE]);

        $this->actingAs($friend, 'sanctum')->getJson("/api/v1/bookings/{$booking->booking_ref}/permit-form")
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.passengers.0.id', $b->id)
            ->assertJsonPath('data.passengers.0.sign_url', null);

        $this->actingAs($friend, 'sanctum')
            ->postJson($this->signUrl($booking, $a), ['signature' => $this->signature(), 'accept' => true])
            ->assertNotFound();
        $this->actingAs($friend, 'sanctum')
            ->postJson($this->signUrl($booking, $b), ['signature' => $this->signature(), 'accept' => true])
            ->assertOk();

        $stranger = User::factory()->create();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/bookings/{$booking->booking_ref}/permit-form")->assertNotFound();
    }

    public function test_booking_lists_flag_trips_that_need_signing(): void
    {
        $signing = $this->booking($this->form());

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.booking_ref', $signing->booking_ref)
            ->assertJsonPath('data.0.permit_sign_required', true);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/bookings/{$signing->booking_ref}")
            ->assertJsonPath('data.permit_sign_required', true);
    }

    public function test_trip_without_a_signature_field_has_nothing_to_sign(): void
    {
        $booking = $this->booking($this->form(withSignature: false));

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/bookings/{$booking->booking_ref}/permit-form")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_preview_carries_filled_values_and_signed_page_urls(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        $p = $booking->passengers->sortBy('id')->first();

        $res = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/permit-form/passengers/{$p->id}")
            ->assertOk()
            ->assertJsonPath('data.signed', false);

        $values = collect($res->json('data.fields'))->pluck('value', 'type')->all();
        $this->assertContains('สมชาย', collect($res->json('data.fields'))->pluck('value'));
        $this->assertContains('1103700123456', collect($res->json('data.fields'))->pluck('value'), 'owner sees full data in the app');
        $this->assertArrayHasKey('signature', $values);

        $this->get($res->json('data.pages.0.url'))->assertOk();
        $this->get("/permit-forms/{$form->id}/pages/1")->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')
            ->get("/api/v1/bookings/{$booking->booking_ref}/permit-form/passengers/{$p->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_friend_link_masks_personal_data_and_signs(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        $p = $booking->passengers->sortBy('id')->first();
        $token = $p->ensurePassToken();

        $this->get("/f/{$token}")->assertOk()->assertSee('อ่านและเซ็นแบบฟอร์ม');

        $this->get("/f/{$token}/permit")
            ->assertOk()
            ->assertSee('สมชาย')
            ->assertSee('3456')
            ->assertDontSee('1103700123456');

        $this->post("/f/{$token}/permit", ['signature' => $this->signature(), 'accept' => '1'])
            ->assertRedirect("/f/{$token}/permit");

        $p->refresh();
        $this->assertTrue($p->hasSignedPermitForm($form->id));
        $this->assertSame('friend_link', $p->permit_sign_meta['channel']);
        $this->get("/f/{$token}/permit")->assertSee('เซ็นแล้ว');
    }

    public function test_signature_lands_in_every_pdf_and_zip_marks_who_has_not_signed(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        [$a] = $booking->passengers->sortBy('id')->values();

        $before = $this->actingAs($this->admin)->get("/api/v1/admin/bookings/{$booking->booking_ref}/permit-form")->getContent();
        $this->actingAs($this->owner, 'sanctum')->postJson($this->signUrl($booking, $a), ['signature' => $this->signature(), 'accept' => true]);
        $after = $this->actingAs($this->admin)->get("/api/v1/admin/bookings/{$booking->booking_ref}/permit-form")->getContent();

        // ภาพพื้นหลังภาพเดียว → เพิ่มลายเซ็น (PNG โปร่งใสมาพร้อม SMask อีกหนึ่งภาพ)
        $this->assertSame(1, preg_match_all('#/Subtype\s*/Image#', $before));
        $this->assertSame(3, preg_match_all('#/Subtype\s*/Image#', $after));

        $res = $this->actingAs($this->admin)->get("/api/v1/admin/schedules/{$booking->schedule_id}/permit-forms?format=zip");
        $res->assertOk();
        $zipPath = $res->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->all();
        $zip->close();

        $this->assertSame(['01-สมชาย ใจดี.pdf', '02-สมหญิง ใจดี (ยังไม่เซ็น).pdf'], $names);

        $this->actingAs($this->admin)->getJson("/api/v1/admin/schedules/{$booking->schedule_id}/manifest")
            ->assertJsonPath('data.permit_form.requires_signature', true);
    }

    public function test_changing_the_form_or_admin_clearing_drops_the_signature(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        $p = $booking->passengers->first();
        $this->actingAs($this->owner, 'sanctum')->postJson($this->signUrl($booking, $p), ['signature' => $this->signature(), 'accept' => true]);
        $path = $p->fresh()->permit_signature_path;

        $newForm = $this->form();
        $booking->schedule->trip->update(['permit_form_id' => $newForm->id]);
        $this->assertFalse($p->fresh()->hasSignedPermitForm($newForm->id), 'consent was given on the old form');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/bookings/{$booking->booking_ref}/passengers/{$p->id}/permit-signature")
            ->assertOk();
        $this->assertNull($p->fresh()->permit_signed_at);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_reminders_go_out_inside_the_window_once_a_day(): void
    {
        $form = $this->form(dueDays: 7);
        // กำหนดเซ็น = D-7 → ทริปอีก 8 วัน = เหลืออีก 1 วันถึงกำหนด (อยู่ในหน้าต่างเตือน)
        $inWindow = $this->booking($form, daysAhead: 8);
        $tooEarly = $this->booking($form, daysAhead: 20);
        $allSigned = $this->booking($form, daysAhead: 8, names: ['คนเดียว เซ็นแล้ว']);
        $this->actingAs($this->owner, 'sanctum')->postJson(
            $this->signUrl($allSigned, $allSigned->passengers->first()),
            ['signature' => $this->signature(), 'accept' => true],
        )->assertOk();

        app()->call([new RemindPermitSignaturesJob, 'handle']);
        app()->call([new RemindPermitSignaturesJob, 'handle']);

        $sent = SmartNotification::where('type', 'permit_sign_reminder')->get();
        $this->assertCount(1, $sent);
        $this->assertSame($inWindow->booking_ref, $sent[0]->data['booking_ref']);
        $this->assertSame('เซ็นแบบฟอร์มอุทยานแล้ว 0/2 คน', $sent[0]->title);
        $this->assertStringEndsWith('#permit', $sent[0]->data['web_url']);
        $this->assertSame(1, $inWindow->fresh()->permit_reminder_count);
        $this->assertSame(0, $tooEarly->fresh()->permit_reminder_count);

        // ลิงก์ในข้อความพามาที่ใบเดินทางซึ่งมีลิงก์เซ็นของแต่ละคน
        $this->get($sent[0]->data['web_url'])->assertOk()->assertSee('เซ็นแบบฟอร์มอุทยาน')->assertSee('กดเพื่ออ่านและเซ็น');
    }

    public function test_confirming_a_booking_asks_the_booker_to_sign_once(): void
    {
        $booking = $this->booking($this->form(), status: 'pending', requested: false);
        $this->assertSame(0, SmartNotification::where('type', 'permit_sign_reminder')->count());

        $booking->update(['status' => 'confirmed']);
        $booking->update(['status' => 'confirmed', 'paid_amount' => 3900]);
        app()->call([new RemindPermitSignaturesJob, 'handle']);

        $sent = SmartNotification::where('type', 'permit_sign_reminder')->get();
        $this->assertCount(1, $sent);
        $this->assertSame('ทริปนี้ต้องเซ็นแบบฟอร์มอุทยาน', $sent[0]->title);
        $this->assertStringContainsString('กรุณาเซ็นภายใน', $sent[0]->body);
        $this->assertSame($this->owner->id, $sent[0]->user_id);
        $this->assertNotNull($booking->fresh()->permit_requested_at);
    }

    public function test_underfilled_rounds_wait_until_the_trip_is_confirmed_to_go(): void
    {
        $booking = $this->booking($this->form(), status: 'pending', requested: false, departureConfirmed: false);
        $booking->update(['status' => 'confirmed']);
        app()->call([new RemindPermitSignaturesJob, 'handle']);

        $this->assertSame(0, SmartNotification::where('type', 'permit_sign_reminder')->count());

        // ทีมงานยืนยันออกเดินทาง → รอบเตือนถัดไปแจ้งครั้งแรกให้
        $booking->schedule->forceFill(['departure_confirmed_at' => now()])->save();
        app()->call([new RemindPermitSignaturesJob, 'handle']);

        $this->assertSame(1, SmartNotification::where('type', 'permit_sign_reminder')->count());
    }

    public function test_confirmation_email_links_to_signing(): void
    {
        $booking = $this->booking($this->form())->load('schedule.trip', 'user', 'passengers');

        $html = (new PaymentConfirmedMail($booking))->render();

        $this->assertStringContainsString('อ่านและลงนามแบบฟอร์มอุทยาน', $html);
        $this->assertStringContainsString($booking->fresh()->briefUrl().'#permit', $html);

        $plain = $this->booking($this->form(withSignature: false))->load('schedule.trip', 'user', 'passengers');
        $this->assertStringNotContainsString('อ่านและลงนามแบบฟอร์มอุทยาน', (new PaymentConfirmedMail($plain))->render());
    }

    public function test_forms_without_a_due_date_never_remind(): void
    {
        $this->booking($this->form(dueDays: null), daysAhead: 1);

        app()->call([new RemindPermitSignaturesJob, 'handle']);

        $this->assertSame(0, SmartNotification::where('type', 'permit_sign_reminder')->count());
    }

    public function test_signatures_are_purged_after_the_trip(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        $p = $booking->passengers->first();
        $this->actingAs($this->owner, 'sanctum')->postJson($this->signUrl($booking, $p), ['signature' => $this->signature(), 'accept' => true]);
        $path = $p->fresh()->permit_signature_path;

        app()->call([new PurgePermitSignaturesJob, 'handle']);
        Storage::disk('local')->assertExists($path);

        $booking->schedule->update([
            'departure_date' => now('Asia/Bangkok')->subDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(18)->toDateString(),
        ]);
        app()->call([new PurgePermitSignaturesJob, 'handle']);

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($p->fresh()->permit_signed_at);
    }

    public function test_signature_image_needs_a_signed_url(): void
    {
        $form = $this->form();
        $booking = $this->booking($form);
        $p = $booking->passengers->first();
        $this->actingAs($this->owner, 'sanctum')->postJson($this->signUrl($booking, $p), ['signature' => $this->signature(), 'accept' => true]);

        $this->get("/permit-signatures/{$p->id}")->assertForbidden();
        $this->get(URL::temporarySignedRoute('public.permit-signature', now()->addMinute(), ['passenger' => $p->id]))->assertOk();
    }
}
