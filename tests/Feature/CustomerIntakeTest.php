<?php

namespace Tests\Feature;

use App\Jobs\PurgeStaleCustomerIntakesJob;
use App\Models\BookingPassenger;
use App\Models\CustomerIntake;
use App\Models\CustomerIntakePerson;
use App\Models\IntakeLink;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function makeLink(?TripSchedule $schedule = null): IntakeLink
    {
        $link = new IntakeLink([
            'trip_schedule_id' => $schedule?->id,
            'label' => 'ไลน์ OA',
            'is_active' => true,
        ]);
        $link->token = IntakeLink::mintToken();
        $link->save();

        return $link;
    }

    /** @return array<string, mixed> */
    private function personPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'phone' => '081-234-5678',
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
}
