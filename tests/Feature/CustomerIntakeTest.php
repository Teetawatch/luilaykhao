<?php

namespace Tests\Feature;

use App\Jobs\NotifyStalledIntakesJob;
use App\Jobs\PurgeStaleCustomerIntakesJob;
use App\Mail\AdminIntakeReadyMail;
use App\Models\BookingPassenger;
use App\Models\CustomerIntake;
use App\Models\CustomerIntakePerson;
use App\Models\IntakeLink;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ลิงก์เก็บข้อมูลลูกค้าก่อนการจอง — ลูกค้าที่ทักมาทางไลน์/เฟส/ไอจีกรอกเอง
 *
 * โจทย์จริงคือกลุ่ม 4-5 คนที่ไม่ได้อยู่พร้อมกัน แต่ละคนกรอกของตัวเองคนละเวลา
 * ข้อมูลของคนที่กรอกไปแล้วจึงต้องค้างอยู่จนกว่าจะครบ แล้วแอดมินค่อยดึงไปเปิดจอง
 */
class CustomerIntakeTest extends TestCase
{
    use RefreshDatabase;

    /** เลขบัตรที่ผ่านหลักตรวจสอบจริง — ใช้ทดสอบว่ากฎยอมรับของที่ถูกต้อง */
    private const VALID_ID = '1101700207251';

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เขาหลวง สุโขทัย',
            'slug' => 'khao-luang-'.uniqid(),
            'type' => 'trekking',
            'location' => 'สุโขทัย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 3500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeLink(?TripSchedule $schedule = null, string $type = IntakeLink::TYPE_NORMAL): IntakeLink
    {
        $link = new IntakeLink([
            'trip_schedule_id' => $schedule?->id,
            'booking_type' => $type,
            'label' => 'ไลน์ OA',
            'is_active' => true,
        ]);
        $link->token = IntakeLink::mintToken();
        $link->save();

        return $link;
    }

    /** @return array<string, mixed> */
    /** ผู้เดินทางที่ไม่มีอีเมล — ใช้ทดสอบว่ากฎบังคับกรอกทำงานจริง */
    private function personBase(array $overrides = []): array
    {
        $payload = $this->personPayload($overrides);
        unset($payload['email']);

        return $payload;
    }

    private function personPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'phone' => '081-234-5678',
            'email' => 'somchai@example.com',
            'consent' => '1',
        ], $overrides);
    }

    public function test_customer_fills_the_public_link_and_lands_on_their_group_page(): void
    {
        $schedule = $this->makeSchedule();
        $link = $this->makeLink($schedule);

        $response = $this->post("/r/{$link->token}", $this->personPayload([
            'party_size' => 3,
            'id_card' => self::VALID_ID,
            'note' => 'ขอที่นั่งติดกันด้วยครับ',
        ]));

        $intake = CustomerIntake::first();
        $this->assertNotNull($intake);
        $response->assertRedirect("/g/{$intake->token}");

        $this->assertSame('new', $intake->status);
        $this->assertSame($schedule->id, $intake->trip_schedule_id);
        $this->assertSame(3, $intake->party_size);
        // เก็บเบอร์เป็นตัวเลขล้วน ไม่งั้นค้นหาและจับคู่คนซ้ำจะพลาด
        $this->assertSame('0812345678', $intake->contact_phone);
        $this->assertNotNull($intake->last_activity_at);

        $lead = $intake->people()->first();
        $this->assertTrue($lead->is_lead);
        $this->assertSame(self::VALID_ID, $lead->id_card);

        // ลิงก์ของทีมงานใช้ซ้ำได้ นับยอดไว้ให้แอดมินเห็นว่าช่องทางไหนเวิร์ก
        $this->assertSame(1, $link->fresh()->uses_count);
    }

    public function test_the_public_form_renders_and_says_plainly_that_it_is_not_a_booking_yet(): void
    {
        $schedule = $this->makeSchedule();
        $link = $this->makeLink($schedule);

        $response = $this->get("/r/{$link->token}");

        $response->assertOk();
        $response->assertSee('เขาหลวง สุโขทัย');
        // ความเข้าใจผิดที่แพงที่สุดคือ "กรอกแล้ว = ได้ที่นั่งแล้ว" ต้องเห็นตั้งแต่ต้นหน้า
        $response->assertSee('ยังไม่ใช่การจอง', false);
    }

    public function test_a_central_link_lets_the_customer_pick_the_round_themselves(): void
    {
        $schedule = $this->makeSchedule();
        $link = $this->makeLink(); // ไม่ผูกรอบ — แปะไว้ในไบโอไอจีได้ตลอดปี

        $this->get("/r/{$link->token}")->assertOk()->assertSee('เลือกรอบเดินทาง');

        $this->post("/r/{$link->token}", $this->personPayload(['schedule_id' => $schedule->id]));

        $this->assertSame($schedule->id, CustomerIntake::first()->trip_schedule_id);
    }

    public function test_a_customer_who_has_not_chosen_a_round_is_still_recorded(): void
    {
        $link = $this->makeLink();

        $this->post("/r/{$link->token}", $this->personPayload());

        $intake = CustomerIntake::first();
        $this->assertNotNull($intake);
        // แอดมินผูกรอบให้ทีหลังได้ ดีกว่าปฏิเสธคนที่ยังไม่รู้ว่าจะไปรอบไหน
        $this->assertNull($intake->trip_schedule_id);
    }

    public function test_friends_fill_their_own_details_later_and_earlier_answers_survive(): void
    {
        $link = $this->makeLink($this->makeSchedule());

        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 3]));
        $intake = CustomerIntake::first();

        $this->post("/g/{$intake->token}", $this->personPayload([
            'name' => 'มานี รักเรียน',
            'nickname' => 'มานี',
            'phone' => '0899999999',
        ]))->assertRedirect("/g/{$intake->token}");

        $this->assertSame(2, $intake->people()->count());
        // คนแรกยังอยู่ครบ ไม่ถูกเขียนทับตอนเพื่อนกรอก
        $this->assertSame('สมชาย ใจดี', $intake->people()->where('is_lead', true)->first()->name);
        $this->assertSame(1, $intake->fresh()->missingCount());
    }

    public function test_filling_again_with_the_same_phone_edits_instead_of_duplicating(): void
    {
        $link = $this->makeLink($this->makeSchedule());
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));
        $intake = CustomerIntake::first();

        // เพื่อนกรอกผิด แล้วกรอกใหม่ด้วยเบอร์เดิม — ต้องทับของเดิม ไม่ใช่เพิ่มคน
        $this->post("/g/{$intake->token}", $this->personPayload([
            'name' => 'มานี รักเรียน',
            'phone' => '0899999999',
            'blood_group' => 'A',
        ]));
        $this->post("/g/{$intake->token}", $this->personPayload([
            'name' => 'มานี รักเรียน',
            'phone' => '089-999-9999',
            'blood_group' => 'O',
        ]));

        $this->assertSame(2, $intake->people()->count());
        $this->assertSame('O', $intake->people()->where('is_lead', false)->first()->blood_group);
    }

    public function test_the_group_page_never_shows_anyone_elses_private_details(): void
    {
        $link = $this->makeLink($this->makeSchedule());
        $this->post("/r/{$link->token}", $this->personPayload([
            'party_size' => 2,
            'id_card' => self::VALID_ID,
            'health_notes' => 'แพ้ยาเพนนิซิลิน',
        ]));
        $intake = CustomerIntake::first();

        $response = $this->get("/g/{$intake->token}");

        $response->assertOk();
        $response->assertSee('ชาย'); // ชื่อเล่นบอกได้ว่าใครกรอกแล้ว
        $response->assertDontSee(self::VALID_ID);
        $response->assertDontSee('แพ้ยาเพนนิซิลิน');
        $response->assertDontSee('0812345678');
    }

    public function test_a_mistyped_id_card_is_rejected_before_it_reaches_the_insurer(): void
    {
        $link = $this->makeLink($this->makeSchedule());

        $this->post("/r/{$link->token}", $this->personPayload(['id_card' => '1101700207250']))
            ->assertSessionHasErrors('id_card');

        $this->assertSame(0, CustomerIntake::count());
    }

    public function test_consent_is_required(): void
    {
        $link = $this->makeLink($this->makeSchedule());

        $payload = $this->personPayload();
        unset($payload['consent']);

        $this->post("/r/{$link->token}", $payload)->assertSessionHasErrors('consent');
        $this->assertSame(0, CustomerIntake::count());
    }

    public function test_a_deactivated_link_stops_working(): void
    {
        $link = $this->makeLink($this->makeSchedule());
        $link->update(['is_active' => false]);

        $this->get("/r/{$link->token}")->assertNotFound();
        $this->post("/r/{$link->token}", $this->personPayload())->assertNotFound();
    }

    public function test_a_group_already_turned_into_a_booking_stops_accepting_submissions(): void
    {
        $link = $this->makeLink($this->makeSchedule());
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));

        $intake = CustomerIntake::first();
        $intake->update(['status' => 'booked']);

        $this->post("/g/{$intake->token}", $this->personPayload([
            'name' => 'มานี รักเรียน',
            'phone' => '0899999999',
        ]))->assertSessionHasErrors('name');

        $this->assertSame(1, $intake->people()->count());
    }

    public function test_purge_deletes_silent_groups_but_keeps_ones_still_being_filled(): void
    {
        $link = $this->makeLink($this->makeSchedule());

        $this->post("/r/{$link->token}", $this->personPayload(['phone' => '0811111111']));
        $stale = CustomerIntake::first();
        $stale->forceFill(['last_activity_at' => now()->subDays(CustomerIntake::RETENTION_DAYS + 1)])->save();

        $this->post("/r/{$link->token}", $this->personPayload(['phone' => '0822222222']));
        $fresh = CustomerIntake::where('contact_phone', '0822222222')->first();

        (new PurgeStaleCustomerIntakesJob)->handle();

        $this->assertNull(CustomerIntake::find($stale->id));
        $this->assertNotNull(CustomerIntake::find($fresh->id));
        // ผู้เดินทางในกลุ่มที่ถูกลบต้องหายตามไปด้วย ไม่ค้างเป็นแถวกำพร้า
        $this->assertSame(0, CustomerIntakePerson::where('customer_intake_id', $stale->id)->count());
    }

    public function test_a_group_a_friend_filled_yesterday_is_not_purged_even_if_opened_long_ago(): void
    {
        $link = $this->makeLink($this->makeSchedule());
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));

        $intake = CustomerIntake::first();
        // เปิดกลุ่มไว้นานมาก แต่เพิ่งมีเพื่อนเข้ามากรอกเมื่อวาน
        $intake->forceFill([
            'created_at' => now()->subDays(CustomerIntake::RETENTION_DAYS + 30),
            'last_activity_at' => now()->subDay(),
        ])->save();

        (new PurgeStaleCustomerIntakesJob)->handle();

        $this->assertNotNull(CustomerIntake::find($intake->id));
    }

    public function test_admin_sees_the_intake_and_can_pull_it_into_a_booking(): void
    {
        Role::findOrCreate('admin', 'web');
        // การจองแทนลูกค้าสร้างบัญชีลูกค้าให้ด้วยถ้ายังไม่มี
        Role::findOrCreate('customer', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule();
        $link = $this->makeLink($schedule);
        $this->post("/r/{$link->token}", $this->personPayload([
            'party_size' => 1,
            'id_card' => self::VALID_ID,
        ]));
        $intake = CustomerIntake::first();

        $list = $this->actingAs($admin)->getJson('/api/v1/admin/intakes');
        $list->assertOk();
        $list->assertJsonPath('data.0.contact_name', 'สมชาย ใจดี');
        $list->assertJsonPath('meta.new_count', 1);
        // หน้ารายการไม่ต้องรู้เลขบัตร จึงไม่ส่งออกไป
        $this->assertStringNotContainsString(self::VALID_ID, $list->getContent());

        $detail = $this->actingAs($admin)->getJson("/api/v1/admin/intakes/{$intake->id}");
        $detail->assertOk();
        $detail->assertJsonPath('data.passengers.0.id_card', self::VALID_ID);

        $booking = $this->actingAs($admin)->postJson('/api/v1/admin/bookings/manual', [
            'schedule_id' => $schedule->id,
            'customer_name' => $intake->contact_name,
            'email' => 'somchai@example.com',
            'phone' => $intake->contact_phone,
            'status' => 'pending',
            'hold_until' => now()->addDays(3)->format('Y-m-d H:i'),
            'passengers' => $detail->json('data.passengers'),
            'intake_id' => $intake->id,
            'send_email' => false,
        ]);

        $booking->assertCreated();
        $intake->refresh();
        $this->assertSame('booked', $intake->status);
        $this->assertNotNull($intake->booking_id);
        $this->assertNotNull($intake->converted_at);
    }

    /**
     * สองคนที่มาด้วยกันแต่กดลิงก์ทีมงานคนละครั้ง = คนละกลุ่ม (เกณฑ์จับคู่คือเบอร์)
     * ทั้งคู่ต้องขึ้นรถคันเดียวกันและเลือกที่นั่งพร้อมกัน จึงต้องดึงรวมเป็นใบเดียวได้
     */
    public function test_two_separate_groups_can_be_pulled_into_one_booking(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('customer', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule();
        $link = $this->makeLink($schedule);

        $this->post("/r/{$link->token}", $this->personPayload());
        $this->post("/r/{$link->token}", $this->personPayload([
            'name' => 'สมหญิง ใจงาม',
            'nickname' => 'หญิง',
            'phone' => '089-999-8888',
        ]));

        $intakes = CustomerIntake::orderBy('id')->get();
        $this->assertCount(2, $intakes);

        $passengers = $intakes
            ->map(fn (CustomerIntake $intake) => $this->actingAs($admin)
                ->getJson("/api/v1/admin/intakes/{$intake->id}")
                ->json('data.passengers.0'))
            ->all();

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/bookings/manual', [
            'schedule_id' => $schedule->id,
            'customer_name' => $intakes[0]->contact_name,
            'email' => 'somchai@example.com',
            'phone' => $intakes[0]->contact_phone,
            'status' => 'pending',
            'hold_until' => now()->addDays(3)->format('Y-m-d H:i'),
            'passengers' => $passengers,
            'intake_ids' => $intakes->pluck('id')->all(),
            'send_email' => false,
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');

        // ทั้งสองกลุ่มต้องถูกปิด ไม่ใช่แค่กลุ่มแรก ไม่งั้นอีกกลุ่มค้างเป็น "ใหม่" ตลอดไป
        foreach ($intakes as $intake) {
            $intake->refresh();
            $this->assertSame('booked', $intake->status);
            $this->assertSame($bookingId, $intake->booking_id);
        }

        $this->assertSame(2, BookingPassenger::where('booking_id', $bookingId)->count());
    }

    /**
     * ข้อมูลที่หน้านี้รับมีเลขบัตร อาหารที่แพ้ และโรคประจำตัว = ข้อมูลอ่อนไหว
     * ตาม PDPA ที่ต้องพิสูจน์ความยินยอมย้อนหลังได้ ไม่ใช่แค่บังคับติ๊กตอนกรอก
     */
    public function test_consent_is_kept_as_proof_not_just_checked(): void
    {
        $link = $this->makeLink($this->makeSchedule());
        $this->post("/r/{$link->token}", $this->personPayload());

        $person = CustomerIntakePerson::latest('id')->firstOrFail();

        $this->assertNotNull($person->consent_at);
        $this->assertSame(CustomerIntakePerson::CONSENT_TEXT, $person->consent_text);
        $this->assertNotNull($person->consent_ip);
    }

    /** กรอกครบ = หยิบไปเปิดการจองได้ ทีมงานต้องรู้โดยไม่ต้องเปิดหน้าแอดมินค้างไว้ */
    public function test_the_team_is_emailed_once_when_a_group_is_complete(): void
    {
        Mail::fake();
        Role::findOrCreate('admin', 'web');
        User::factory()->create(['email' => 'admin@luilaykhao.com'])->assignRole('admin');

        $link = $this->makeLink($this->makeSchedule());
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));

        // ยังรอเพื่อนอีกคน — ยังไม่มีอะไรให้ทีมงานทำ
        Mail::assertNothingQueued();

        $intake = CustomerIntake::latest('id')->firstOrFail();
        $friend = $this->personPayload([
            'name' => 'สมหญิง ใจงาม',
            'phone' => '089-999-9999',
            'email' => 'somying@example.com',
        ]);
        $this->post("/g/{$intake->token}", $friend);

        Mail::assertQueued(AdminIntakeReadyMail::class, 1);

        // กรอกซ้ำ/แก้ข้อมูลทีหลังต้องไม่ยิงเมลอีกฉบับ
        $this->post("/g/{$intake->token}", $friend);

        Mail::assertQueued(AdminIntakeReadyMail::class, 1);
    }

    /**
     * กลุ่มที่แจ้งว่ามา 4 คนแต่กรอกแค่ 2 จะไม่มีวันครบเอง ถ้าไม่ตามเก็บก็จม
     * อยู่ในหน้าแอดมินเงียบ ๆ จนถูกลบทิ้งตามอายุข้อมูล
     */
    public function test_a_group_that_stalls_half_filled_is_still_reported(): void
    {
        Mail::fake();
        Role::findOrCreate('admin', 'web');
        User::factory()->create(['email' => 'admin@luilaykhao.com'])->assignRole('admin');

        $link = $this->makeLink($this->makeSchedule());
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 4]));

        $intake = CustomerIntake::latest('id')->firstOrFail();
        $intake->forceFill([
            'last_activity_at' => now()->subHours(NotifyStalledIntakesJob::STALE_HOURS + 1),
        ])->save();

        (new NotifyStalledIntakesJob)->handle(app(MailService::class));

        Mail::assertQueued(AdminIntakeReadyMail::class, 1);
        $this->assertNotNull($intake->fresh()->team_notified_at);

        // รันซ้ำวันถัดไปต้องไม่ทวงซ้ำ
        (new NotifyStalledIntakesJob)->handle(app(MailService::class));
        Mail::assertQueued(AdminIntakeReadyMail::class, 1);
    }

    /**
     * ลิงก์นี้เดินทางด้วยการวางในแชทเป็นหลัก ถ้าไม่มีการ์ดพรีวิว ลูกค้าเห็นแค่
     * URL สุ่มยาว ๆ แล้วลังเลที่จะกด — และหน้ากลุ่มยิ่งสำคัญ เพราะลูกค้าเป็นคน
     * ส่งต่อเอง การ์ดคือสิ่งที่อธิบายแทนเขา
     */
    public function test_both_public_pages_unfurl_as_a_card_in_chat(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->trip->update(['cover_image' => 'https://cdn.example.com/khao-luang.jpg']);
        $link = $this->makeLink($schedule);

        $this->get("/r/{$link->token}")
            ->assertOk()
            ->assertSee('og:title', false)
            ->assertSee('กรอกข้อมูลผู้เดินทาง · เขาหลวง สุโขทัย', false)
            ->assertSee('https://cdn.example.com/khao-luang.jpg', false)
            ->assertSee('summary_large_image', false);

        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));
        $intake = CustomerIntake::latest('id')->firstOrFail();

        $this->get("/g/{$intake->token}")
            ->assertOk()
            ->assertSee('กรอกข้อมูลผู้เดินทางของกลุ่ม · เขาหลวง สุโขทัย', false)
            // การ์ดถูกอ่านโดยคนทั้งแชทกลุ่ม ห้ามมีชื่อใครหลุดไปอยู่ในนั้น
            ->assertDontSee('<meta property="og:description" content="สมชาย', false);
    }

    private function makePickupPoint(TripSchedule $schedule, string $name, float $price = 2500): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => $name,
            'price' => $price,
            'image_url' => 'https://cdn.example.com/'.md5($name).'.jpg',
            'pickup_time' => '04:30',
        ]);
    }

    /**
     * คนที่รู้ว่าตัวเองขึ้นรถที่ไหนคือเจ้าตัว ไม่ใช่คนที่กดลิงก์มาก่อน — และรูป
     * คือสิ่งที่ทำให้ไปยืนถูกที่ตอนตีสี่ ชื่อจุดอย่างเดียวไม่พอ
     */
    public function test_each_person_picks_their_own_pickup_point_with_a_photo(): void
    {
        $schedule = $this->makeSchedule();
        $rangsit = $this->makePickupPoint($schedule, 'ปั๊ม ปตท. รังสิต', 2200);
        $ladprao = $this->makePickupPoint($schedule, 'BTS ลาดพร้าว', 2500);
        $link = $this->makeLink($schedule);

        $this->get("/r/{$link->token}")
            ->assertOk()
            ->assertSee('name="pickup_point_id"', false)
            ->assertSee('ปั๊ม ปตท. รังสิต')
            ->assertSee($rangsit->image_url, false)
            ->assertSee('นัดหมาย 04:30 น.')
            ->assertSee('2,200 บาท / ท่าน');

        $this->post("/r/{$link->token}", $this->personPayload([
            'party_size' => 2,
            'pickup_point_id' => $rangsit->id,
        ]));

        $intake = CustomerIntake::latest('id')->firstOrFail();
        $this->assertSame($rangsit->id, $intake->people()->first()->pickup_point_id);

        // เพื่อนในกลุ่มเลือกคนละจุดได้ ไม่ต้องตรงกับคนแรก
        $this->post("/g/{$intake->token}", $this->personPayload([
            'name' => 'สมหญิง ใจงาม',
            'phone' => '089-999-9999',
            'email' => 'somying@example.com',
            'pickup_point_id' => $ladprao->id,
        ]));

        $this->assertSame(
            [$rangsit->id, $ladprao->id],
            $intake->people()->orderBy('id')->pluck('pickup_point_id')->all(),
        );
    }

    /** รอบที่มีจุดรับ ต้องเลือกให้ครบทุกคน และเลือกได้เฉพาะจุดของรอบนี้ */
    public function test_the_pickup_point_is_required_when_the_round_has_any(): void
    {
        $schedule = $this->makeSchedule();
        $this->makePickupPoint($schedule, 'ปั๊ม ปตท. รังสิต');
        $otherRound = $this->makePickupPoint($this->makeSchedule(), 'จุดของรอบอื่น');
        $link = $this->makeLink($schedule);

        $this->post("/r/{$link->token}", $this->personPayload())
            ->assertSessionHasErrors('pickup_point_id');

        $this->post("/r/{$link->token}", $this->personPayload(['pickup_point_id' => $otherRound->id]))
            ->assertSessionHasErrors('pickup_point_id');

        $this->assertSame(0, CustomerIntake::count());
    }

    /**
     * ลิงก์กลางยังไม่รู้ว่ารอบไหน จุดรับเป็นของรอบ จึงไม่มีรายการที่ถูกต้องให้เลือก
     * — บังคับไม่ได้ ต้องไม่ปิดประตูใส่คนที่แค่อยากฝากข้อมูลไว้
     */
    public function test_a_central_link_does_not_ask_for_a_pickup_point(): void
    {
        $schedule = $this->makeSchedule();
        $this->makePickupPoint($schedule, 'ปั๊ม ปตท. รังสิต');

        $link = $this->makeLink();

        $this->get("/r/{$link->token}")->assertOk()->assertDontSee('name="pickup_point_id"', false);
        $this->post("/r/{$link->token}", $this->personPayload(['schedule_id' => $schedule->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CustomerIntake::count());
    }

    /** จุดที่เลือกไว้ต้องไหลไปถึงฟอร์มจองแทนลูกค้า ไม่ใช่ตกหล่นตอนดึงไปจอง */
    public function test_the_chosen_point_travels_into_the_booking_prefill(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule();
        $point = $this->makePickupPoint($schedule, 'ปั๊ม ปตท. รังสิต');
        $link = $this->makeLink($schedule);

        $this->post("/r/{$link->token}", $this->personPayload(['pickup_point_id' => $point->id]));
        $intake = CustomerIntake::latest('id')->firstOrFail();

        $data = $this->actingAs($admin)
            ->getJson("/api/v1/admin/intakes/{$intake->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($point->id, $data['passengers'][0]['pickup_point_id']);
        $this->assertSame('ปั๊ม ปตท. รังสิต', $data['people'][0]['pickup_label']);
    }

    /**
     * อีเมลบังคับกรอกทั้งสองประตู — ใบเสร็จ กำหนดการ และอีเมลยืนยันการจอง
     * ส่งทางนี้ทางเดียว ไม่มีอีเมลคือลูกค้าไม่ได้รับเอกสารอะไรเลย
     */
    public function test_an_email_is_required_on_both_doors(): void
    {
        $link = $this->makeLink($this->makeSchedule());

        $payload = $this->personPayload();
        unset($payload['email']);

        $this->post("/r/{$link->token}", $payload)->assertSessionHasErrors('email');
        $this->assertSame(0, CustomerIntake::count());

        // ประตูของกลุ่มก็ต้องเหมือนกัน เพื่อนแต่ละคนมีอีเมลของตัวเอง
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));
        $intake = CustomerIntake::latest('id')->firstOrFail();

        $friend = $this->personBase();
        $this->post("/g/{$intake->token}", $friend)->assertSessionHasErrors('email');

        // และรูปแบบต้องเป็นอีเมลจริง ไม่ใช่ข้อความอะไรก็ได้
        $this->post("/r/{$link->token}", $this->personPayload(['email' => 'ไม่มีอีเมลครับ']))
            ->assertSessionHasErrors('email');
    }

    /** QR ไว้แปะ rich menu ไลน์ / สตอรี่ไอจี / ป้ายหน้าบูธ ที่คัดลอก URL ไม่ได้ */
    public function test_admin_can_get_a_qr_for_a_link(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $link = $this->makeLink($this->makeSchedule());

        $data = $this->actingAs($admin)
            ->getJson("/api/v1/admin/intake-links/{$link->id}/qr")
            ->assertOk()
            ->json('data');

        $this->assertSame($link->publicUrl(), $data['url']);
        $this->assertStringStartsWith('<svg', $data['svg']);
    }

    /** คนนอกหยิบ QR ของลิงก์ไปไม่ได้ */
    public function test_the_qr_endpoint_is_admin_only(): void
    {
        $link = $this->makeLink();

        $this->getJson("/api/v1/admin/intake-links/{$link->id}/qr")->assertUnauthorized();
    }

    /**
     * ลิงก์ถูกแปะค้างไว้ในไบโอไอจี/auto-reply — พอรอบเต็ม ลิงก์เดิมก็ยังถูกเปิดอยู่
     * ปล่อยให้กรอกจนจบแล้วค่อยตอบว่าเต็มคือความผิดหวังที่หลบได้ตั้งแต่แรก
     */
    public function test_a_full_round_says_so_before_the_customer_starts_typing(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update(['booked_seats' => $schedule->total_seats]);

        $sibling = TripSchedule::create([
            'trip_id' => $schedule->trip_id,
            'departure_date' => now('Asia/Bangkok')->addMonths(2)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonths(2)->addDay()->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $link = $this->makeLink($schedule);

        $this->get("/r/{$link->token}")
            ->assertOk()
            ->assertSee('รอบนี้เต็มแล้ว')
            // ไม่ได้ปิดประตูใส่ — เสนอรอบอื่นของทริปเดียวกันตรงนั้นเลย
            ->assertSee('ย้ายไปรอบอื่นของทริปนี้')
            ->assertSee('<option value="'.$sibling->id.'"', false);

        // และย้ายรอบได้จริง ทั้งที่ลิงก์ผูกไว้กับรอบที่เต็ม
        $this->post("/r/{$link->token}", $this->personPayload(['schedule_id' => $sibling->id]));

        $this->assertSame($sibling->id, CustomerIntake::latest('id')->firstOrFail()->trip_schedule_id);
    }

    /** รอบที่เต็มแล้วไม่ควรโผล่ในลิสต์ของลิงก์กลาง เลือกไปก็ได้คำตอบเดิม */
    public function test_a_central_link_never_offers_a_full_round(): void
    {
        $full = $this->makeSchedule();
        $full->update(['booked_seats' => $full->total_seats]);
        $open = $this->makeSchedule();

        $this->get('/r/'.$this->makeLink()->token)
            ->assertOk()
            ->assertSee('<option value="'.$open->id.'"', false)
            ->assertDontSee('<option value="'.$full->id.'"', false);

        // ยัดไอดีรอบที่เต็มมาเองก็ไม่ผูกให้ ปล่อยเป็น "ยังไม่ระบุรอบ" ให้ทีมงานจัดการ
        $this->post('/r/'.$this->makeLink()->token, $this->personPayload(['schedule_id' => $full->id]));

        $this->assertNull(CustomerIntake::latest('id')->firstOrFail()->trip_schedule_id);
    }

    /** ลิงก์ที่ผูกรอบไว้และรอบยังเปิดอยู่ ห้ามให้ใครส่งรอบอื่นมาทับ */
    public function test_a_bound_link_on_an_open_round_refuses_a_swapped_round(): void
    {
        $schedule = $this->makeSchedule();
        $other = $this->makeSchedule();
        $link = $this->makeLink($schedule);

        $this->post("/r/{$link->token}", $this->personPayload(['schedule_id' => $other->id]))
            ->assertSessionHasErrors('schedule_id');
    }

    /**
     * ลูกค้าเปิดลิงก์จากแชทโดยไม่ได้ผ่านหน้าทริป ถ้าไม่บอกว่าเป็นรอบวันไหน
     * ก็เท่ากับให้กรอกข้อมูลส่วนตัวไปโดยไม่รู้ว่ากำลังตอบรับทริปวันไหน
     */
    public function test_the_form_tells_the_customer_which_round_it_is_for(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update([
            'departure_date' => '2026-09-05',
            'return_date' => '2026-09-07',
            'departs_at' => '2026-09-04 20:00:00',
        ]);
        $link = $this->makeLink($schedule);

        $this->get("/r/{$link->token}")
            ->assertOk()
            ->assertSee('รอบเดินทางที่คุณกำลังกรอก')
            // ย่อเดือน/ปีที่ซ้ำกันออก ไม่ใช่ "5 กันยายน 2569 – 7 กันยายน 2569"
            ->assertSee('5 – 7 กันยายน 2569')
            ->assertSee('เวลา 20:00 น.')
            ->assertSee('3 วัน 2 คืน');
    }

    /** เพื่อนที่รับลิงก์กลุ่มต่อมาก็ต้องเห็นรอบเหมือนกัน ไม่ใช่เห็นแค่ชื่อทริป */
    public function test_the_group_page_shows_the_round_too(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update(['departure_date' => '2026-09-05', 'return_date' => '2026-09-05', 'departs_at' => null]);
        $link = $this->makeLink($schedule);
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));

        $intake = CustomerIntake::latest('id')->firstOrFail();

        $this->get("/g/{$intake->token}")
            ->assertOk()
            ->assertSee('รอบเดินทางของกลุ่มนี้')
            // ไปกลับวันเดียว ไม่ต้องมีขีดคั่น
            ->assertSee('5 กันยายน 2569')
            ->assertDontSee('–');
    }

    /**
     * หน้าแอดมินวาดรอบที่ลิงก์ผูกอยู่เป็นการ์ดรูป+วันที่ ไม่ใช่บรรทัดข้อความ
     * จึงต้องได้ชิ้นส่วนแยกมาด้วย ไม่ใช่แค่ schedule_label ที่ต่อไว้แล้ว
     */
    public function test_link_list_carries_the_pieces_needed_to_draw_the_round(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule();
        $schedule->trip->update(['thumbnail_image' => 'https://cdn.example.com/khao-luang.jpg']);
        $this->makeLink($schedule);
        $this->makeLink();

        $links = $this->actingAs($admin)->getJson('/api/v1/admin/intake-links')->assertOk()->json('data');

        $bound = collect($links)->firstWhere('trip_schedule_id', $schedule->id);
        $this->assertSame('เขาหลวง สุโขทัย', $bound['schedule_trip_title']);
        $this->assertSame($schedule->departure_date->toDateString(), $bound['schedule_departure_date']);
        $this->assertSame('https://cdn.example.com/khao-luang.jpg', $bound['schedule_image']);

        // ลิงก์กลางไม่มีรอบผูกอยู่ — หน้าจอต้องแยกได้ว่าไม่ใช่ "ยังไม่มีข้อมูล"
        $general = collect($links)->firstWhere('trip_schedule_id', null);
        $this->assertNull($general['schedule_trip_title']);
        $this->assertNull($general['schedule_image']);
    }

    /**
     * ตัวเลือกรอบดึงมาหน้าเดียว 200 รอบ ถ้าเรียงใหม่→เก่าตามค่าเริ่มต้น
     * รอบที่ใกล้จะถึงจะตกไปอยู่หน้าท้าย ๆ ซึ่งเป็นรอบที่แอดมินต้องใช้บ่อยที่สุด
     */
    public function test_schedule_list_can_be_ordered_soonest_first(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $soon = $this->makeSchedule();
        $soon->update(['departure_date' => now('Asia/Bangkok')->addDays(3)->toDateString()]);
        $later = $this->makeSchedule();
        $later->update(['departure_date' => now('Asia/Bangkok')->addMonths(6)->toDateString()]);

        $ids = $this->actingAs($admin)
            ->getJson('/api/v1/admin/schedules?upcoming=1&order=asc')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$soon->id, $later->id], $ids);

        // ไม่ส่ง order มา ต้องยังเป็นใหม่→เก่าเหมือนเดิมสำหรับหน้าอื่น
        $default = $this->actingAs($admin)
            ->getJson('/api/v1/admin/schedules?upcoming=1')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$later->id, $soon->id], $default);
    }

    /**
     * ── จอยทริป ────────────────────────────────────────────────────────────
     *
     * คนจอยทริปขับรถไปเจอกันที่จุดหมาย ไม่มีรถของทริปไปรับ การถามจุดขึ้นรถจึงไม่ใช่
     * แค่เกินจำเป็น แต่ทำให้เขาเข้าใจว่าจะมีรถมารับ ซึ่งรู้ตัวอีกทีตอนวันเดินทาง
     */
    public function test_a_join_trip_link_skips_the_pickup_step_and_records_the_type(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update(['join_trip_enabled' => true, 'join_trip_price' => 1800]);
        $this->makePickupPoint($schedule, 'ปั๊ม ปตท. รังสิต');

        $link = $this->makeLink($schedule, IntakeLink::TYPE_JOIN);

        $this->get("/r/{$link->token}")
            ->assertOk()
            ->assertSee('จอยทริป')
            ->assertSee('ไม่มีรถของทริปไปรับ')
            ->assertDontSee('name="pickup_point_id"', false);

        // ไม่ส่งจุดขึ้นรถมาก็ผ่าน — ต่างจากรอบเดียวกันแบบจองปกติที่บังคับ
        // และต่อให้ยัดจุดขึ้นรถมาเอง ก็ต้องไม่ติดไปกับกลุ่มจอย
        $point = $schedule->pickupPoints()->firstOrFail();
        $this->post("/r/{$link->token}", $this->personPayload([
            'pickup_point_id' => $point->id,
        ]))->assertSessionHasNoErrors();

        $intake = CustomerIntake::latest('id')->firstOrFail();
        $this->assertSame(CustomerIntake::TYPE_JOIN, $intake->booking_type);
        $this->assertNull($intake->people()->first()->pickup_point_id);
    }

    /** เพื่อนที่ตามมากรอกทีหลังเป็นประเภทเดียวกับกลุ่มเสมอ — ใบจองมีประเภทเดียว */
    public function test_friends_of_a_join_group_are_not_asked_for_a_pickup_point_either(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update(['join_trip_enabled' => true]);
        $this->makePickupPoint($schedule, 'ปั๊ม ปตท. รังสิต');

        $link = $this->makeLink($schedule, IntakeLink::TYPE_JOIN);
        $this->post("/r/{$link->token}", $this->personPayload(['party_size' => 2]));
        $intake = CustomerIntake::latest('id')->firstOrFail();

        $this->get("/g/{$intake->token}")
            ->assertOk()
            ->assertSee('กลุ่มนี้เป็นจอยทริป')
            ->assertDontSee('name="pickup_point_id"', false);

        $this->post("/g/{$intake->token}", $this->personPayload([
            'name' => 'สมหญิง ใจงาม',
            'phone' => '089-999-9999',
            'email' => 'somying@example.com',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, $intake->people()->count());
    }

    /** ลิงก์ที่ยังไม่ล็อกประเภท ให้ลูกค้าตอบเอง แล้วเก็บคำตอบไว้ให้ทีมงานเห็น */
    public function test_an_ask_link_lets_the_customer_choose_how_they_travel(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update(['join_trip_enabled' => true]);
        $this->makePickupPoint($schedule, 'ปั๊ม ปตท. รังสิต');

        $link = $this->makeLink($schedule, IntakeLink::TYPE_ASK);

        $this->get("/r/{$link->token}")
            ->assertOk()
            ->assertSee('name="booking_type"', false)
            ->assertSee('เดินทางแบบไหน')
            // จุดขึ้นรถยังอยู่ในหน้า แต่สคริปต์ต้องมาด้วย ไม่งั้นเลือก "จอย" แล้ว
            // ช่องที่ยัง required อยู่จะบล็อกการส่งฟอร์มโดยลูกค้าไม่เห็นว่าติดตรงไหน
            ->assertSee('data-pickup-block', false)
            ->assertSee('data-booking-type', false);

        $this->post("/r/{$link->token}", $this->personPayload([
            'booking_type' => IntakeLink::TYPE_JOIN,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            CustomerIntake::TYPE_JOIN,
            CustomerIntake::latest('id')->firstOrFail()->booking_type,
        );
    }

    /** เลือกจอยกับรอบที่ไม่ได้เปิดจอย = เลือกสิ่งที่ไม่มีขาย ต้องบอกกลับ ไม่ใช่เงียบ ๆ เปลี่ยนให้ */
    public function test_choosing_join_on_a_round_without_it_is_refused(): void
    {
        $schedule = $this->makeSchedule(); // join_trip_enabled ยังปิดอยู่
        $link = $this->makeLink($schedule, IntakeLink::TYPE_ASK);

        $this->post("/r/{$link->token}", $this->personPayload([
            'booking_type' => IntakeLink::TYPE_JOIN,
        ]))->assertSessionHasErrors('booking_type');

        $this->assertSame(0, CustomerIntake::count());
    }

    /** ค่าที่ส่งมาจากเบราว์เซอร์แก้ได้ ลิงก์ที่ล็อกไว้แล้วจึงต้องไม่ถูกมันล้มล้าง */
    public function test_a_locked_link_ignores_a_type_sent_by_the_browser(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update(['join_trip_enabled' => true]);
        $link = $this->makeLink($schedule, IntakeLink::TYPE_NORMAL);

        $this->post("/r/{$link->token}", $this->personPayload([
            'booking_type' => IntakeLink::TYPE_JOIN,
        ]));

        $this->assertSame(
            CustomerIntake::TYPE_NORMAL,
            CustomerIntake::latest('id')->firstOrFail()->booking_type,
        );
    }

    /**
     * รถเต็มไม่ได้แปลว่ารอบนี้ขายอะไรไม่ได้แล้ว — คนจอยไม่ได้กินที่นั่งบนรถ
     * ลิงก์จอยจึงต้องไม่ปิดตัวเองตามที่นั่งบนรถ
     */
    public function test_a_round_whose_van_is_full_still_takes_join_trip_customers(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update([
            'booked_seats' => $schedule->total_seats,
            'join_trip_enabled' => true,
            'join_trip_seats' => 5,
        ]);

        $link = $this->makeLink($schedule, IntakeLink::TYPE_JOIN);

        $this->get("/r/{$link->token}")->assertOk()->assertDontSee('รอบนี้ปิดรับแล้ว');
        $this->post("/r/{$link->token}", $this->personPayload())->assertSessionHasNoErrors();

        $this->assertSame($schedule->id, CustomerIntake::latest('id')->firstOrFail()->trip_schedule_id);
    }

    /** โควตาจอยเต็มแล้วก็ต้องบอกก่อนกรอก เหมือนที่นั่งบนรถเต็ม */
    public function test_a_join_link_says_so_when_the_join_quota_is_full(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update([
            'join_trip_enabled' => true,
            'join_trip_seats' => 2,
            'join_trip_booked_seats' => 2,
        ]);

        $link = $this->makeLink($schedule, IntakeLink::TYPE_JOIN);

        $this->get("/r/{$link->token}")->assertOk()->assertSee('รอบนี้ปิดรับแล้ว');
    }

    /** ออกลิงก์จอยกับรอบที่ไม่ได้เปิดจอย = ลิงก์ที่พาลูกค้าไปหาสิ่งที่ขายไม่ได้ */
    public function test_admin_cannot_create_a_join_link_for_a_round_without_join(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule();

        $this->actingAs($admin)->postJson('/api/v1/admin/intake-links', [
            'trip_schedule_id' => $schedule->id,
            'booking_type' => IntakeLink::TYPE_JOIN,
        ])->assertStatus(422);

        $schedule->update(['join_trip_enabled' => true]);

        $this->actingAs($admin)->postJson('/api/v1/admin/intake-links', [
            'trip_schedule_id' => $schedule->id,
            'booking_type' => IntakeLink::TYPE_JOIN,
        ])->assertCreated()->assertJsonPath('data.booking_type', IntakeLink::TYPE_JOIN);
    }

    /** ปลายทางของทั้งเรื่อง: กลุ่มจอยทริปต้องกลายเป็นใบจองแบบจอย ไม่ใช่กินที่นั่งบนรถ */
    public function test_a_join_group_becomes_a_join_booking(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('customer', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule();
        $schedule->update(['join_trip_enabled' => true, 'join_trip_price' => 1800]);

        $link = $this->makeLink($schedule, IntakeLink::TYPE_JOIN);
        $this->post("/r/{$link->token}", $this->personPayload(['id_card' => self::VALID_ID]));
        $intake = CustomerIntake::latest('id')->firstOrFail();

        $detail = $this->actingAs($admin)->getJson("/api/v1/admin/intakes/{$intake->id}")->assertOk();
        $this->assertSame(CustomerIntake::TYPE_JOIN, $detail->json('data.booking_type'));

        $this->actingAs($admin)->postJson('/api/v1/admin/bookings/manual', [
            'schedule_id' => $schedule->id,
            'customer_name' => $intake->contact_name,
            'email' => 'somchai@example.com',
            'phone' => $intake->contact_phone,
            'status' => 'pending',
            'is_join_trip' => true,
            'passengers' => $detail->json('data.passengers'),
            'intake_id' => $intake->id,
            'send_email' => false,
        ])->assertCreated();

        $booking = $intake->fresh()->booking;
        $this->assertTrue((bool) $booking->is_join_trip);
        // คนจอยไม่กินที่นั่งบนรถ — ที่นั่งที่ขายได้ต้องไม่ลดลง
        $this->assertSame(0, (int) $schedule->fresh()->booked_seats);
        $this->assertSame(1, (int) $schedule->fresh()->join_trip_booked_seats);
    }

    /**
     * เมลที่บอกทีมงานว่า "หยิบไปจองได้แล้ว" ต้องบอกด้วยว่าเป็นกลุ่มแบบไหน —
     * และรอบที่รถเต็มแต่จอยยังว่างต้องไม่ขึ้นว่าปิดรับ
     */
    public function test_the_ready_email_says_the_group_is_a_join_trip(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update([
            'booked_seats' => $schedule->total_seats,
            'join_trip_enabled' => true,
            'join_trip_seats' => 4,
        ]);

        $link = $this->makeLink($schedule, IntakeLink::TYPE_JOIN);
        $this->post("/r/{$link->token}", $this->personPayload());

        $intake = CustomerIntake::latest('id')->firstOrFail();
        $html = (new AdminIntakeReadyMail($intake, 'complete'))->render();

        $this->assertStringContainsString('จอยทริป', $html);
        $this->assertStringContainsString('เหลือ 4 ที่', $html);
        $this->assertStringNotContainsString('รอบนี้ปิดรับแล้ว', $html);
    }

    /** หน้าแอดมินต้องแยกสองประเภทออกจากกันได้ก่อนกด "ดึงไปจอง" */
    public function test_admin_list_shows_and_filters_by_booking_type(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule();
        $schedule->update(['join_trip_enabled' => true]);

        $this->post('/r/'.$this->makeLink($schedule)->token, $this->personPayload());
        $this->post('/r/'.$this->makeLink($schedule, IntakeLink::TYPE_JOIN)->token, $this->personPayload([
            'name' => 'สมหญิง ใจงาม',
            'phone' => '089-999-9999',
            'email' => 'somying@example.com',
        ]));

        $all = $this->actingAs($admin)->getJson('/api/v1/admin/intakes?status=new')->assertOk()->json('data');
        $this->assertCount(2, $all);

        $join = $this->actingAs($admin)
            ->getJson('/api/v1/admin/intakes?status=new&booking_type=join')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $join);
        $this->assertSame('สมหญิง ใจงาม', $join[0]['contact_name']);
        $this->assertSame(CustomerIntake::TYPE_JOIN, $join[0]['booking_type']);
    }
}
