<?php

namespace Tests\Feature;

use App\Jobs\PostChatAutoAnswerJob;
use App\Mail\TripBriefMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatAutoAnswerService;
use App\Services\TripBriefService;
use App\Services\TripFactsService;
use App\Support\TripMealPlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * มื้ออาหารของทริป — "อาหารกี่มื้อ มื้อไหนบ้าง ต้องจ่ายเองไหม"
 *
 * ทุกหน้าจออ่านคำจาก TripMealPlan ที่เดียว ชุดเทสนี้จึงคุมทั้งตัวนับ ทางเข้า
 * ของแอดมิน และทุกที่ที่ลูกค้าเห็น (หน้าทริป ใบเดินทาง แชท)
 */
class TripMealPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-09-10 10:00 Bangkok — รอบในเทสออกเดินทาง 2026-09-12
        Carbon::setTestNow(Carbon::parse('2026-09-10 03:00:00', 'UTC'));
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Category::create(['name' => 'เดินป่า', 'slug' => 'trekking']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ทริป 2 วัน: วันแรกกลางวันจ่ายเอง เย็นหมูกระทะ · วันสองเช้า + กลางวันข้าวกล่อง */
    private function plan(): array
    {
        return [
            'days' => [
                [
                    'breakfast' => ['status' => 'none', 'note' => ''],
                    'lunch' => ['status' => 'self', 'note' => 'แวะร้านริมทาง'],
                    'dinner' => ['status' => 'included', 'note' => 'หมูกระทะ'],
                ],
                [
                    'breakfast' => ['status' => 'included', 'note' => ''],
                    'lunch' => ['status' => 'included', 'note' => 'ข้าวกล่อง'],
                    'dinner' => ['status' => 'none', 'note' => ''],
                ],
            ],
            'note' => 'มีเมนูมังสวิรัติ แจ้งทีมงานล่วงหน้า',
        ];
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูกระดึง 2 วัน 1 คืน',
            'slug' => 'meal-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3200,
            'status' => 'active',
        ], $overrides));
    }

    private function schedule(Trip $trip): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-09-12',
            'return_date' => '2026-09-13',
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function adminPayload(Trip $trip, array $extra): array
    {
        return array_merge([
            'title' => $trip->title,
            'type' => 'trekking',
            'location' => $trip->location,
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => $trip->duration_days,
            'max_participants' => 12,
            'price_per_person' => 3200,
        ], $extra);
    }

    // ── ตัวนับและคำ ───────────────────────────────────────────────────────

    public function test_counts_included_and_self_paid_meals_separately(): void
    {
        $meals = TripMealPlan::present($this->plan(), 2);

        $this->assertSame(3, $meals['included_count']);
        $this->assertSame(1, $meals['self_count']);
        $this->assertSame('อาหาร 3 มื้อ', $meals['label']);
        $this->assertSame('รวมอาหาร 3 มื้อ · จ่ายเอง 1 มื้อ', $meals['summary']);
        $this->assertSame('กลางวัน – แวะร้านริมทาง (จ่ายเอง) · เย็น – หมูกระทะ', $meals['days'][0]['line']);
        $this->assertSame('เช้า · กลางวัน – ข้าวกล่อง', $meals['days'][1]['line']);
        $this->assertSame('มีเมนูมังสวิรัติ แจ้งทีมงานล่วงหน้า', $meals['note']);
    }

    public function test_a_plan_with_no_meals_says_so_instead_of_disappearing(): void
    {
        $none = ['status' => 'none'];
        $meals = TripMealPlan::present(['days' => [['breakfast' => $none, 'lunch' => $none, 'dinner' => $none]]], 1);

        $this->assertSame('ไม่รวมอาหาร', $meals['label']);
        $this->assertSame('ไม่รวมอาหาร', $meals['summary']);
        $this->assertSame('ไม่มีมื้ออาหาร', $meals['days'][0]['line']);
    }

    public function test_unknown_status_never_turns_into_an_included_meal(): void
    {
        $plan = TripMealPlan::normalize(['days' => [[
            'breakfast' => ['status' => 'free'],
            'lunch' => 'included',
        ]]], 1);

        $this->assertSame('none', $plan['days'][0]['breakfast']['status']);
        $this->assertSame('none', $plan['days'][0]['lunch']['status']);
        $this->assertSame('none', $plan['days'][0]['dinner']['status']);
    }

    public function test_days_beyond_the_trip_length_are_dropped(): void
    {
        $plan = TripMealPlan::normalize($this->plan(), 1);

        $this->assertCount(1, $plan['days']);
    }

    public function test_unset_plan_is_null_not_an_empty_table(): void
    {
        $this->assertNull(TripMealPlan::present(null, 2));
        $this->assertNull(TripMealPlan::present(['days' => []], 2));
        $this->assertNull(TripMealPlan::text(null, 2));
    }

    public function test_a_known_round_adds_real_dates_to_each_day(): void
    {
        $meals = TripMealPlan::present($this->plan(), 2, Carbon::parse('2026-09-12'));

        $this->assertSame('2026-09-12', $meals['days'][0]['date']);
        $this->assertSame('2026-09-13', $meals['days'][1]['date']);
        $this->assertSame('13 ก.ย. 2569', $meals['days'][1]['date_label']);
    }

    // ── แอดมิน ────────────────────────────────────────────────────────────

    public function test_admin_saves_the_meal_plan_and_reads_it_back(): void
    {
        $trip = $this->trip();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->adminPayload($trip, [
                'meal_plan' => $this->plan(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.meal_plan.days.0.dinner.status', 'included')
            ->assertJsonPath('data.meal_plan.days.0.dinner.note', 'หมูกระทะ')
            ->assertJsonPath('data.meals.summary', 'รวมอาหาร 3 มื้อ · จ่ายเอง 1 มื้อ');

        $this->assertSame(3, $trip->fresh()->meals()['included_count']);
    }

    public function test_admin_can_switch_the_meal_plan_off(): void
    {
        $trip = $this->trip(['meal_plan' => $this->plan()]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->adminPayload($trip, [
                'meal_plan' => null,
            ]))
            ->assertOk()
            ->assertJsonPath('data.meals', null);

        $this->assertNull($trip->fresh()->meal_plan);
    }

    public function test_saving_without_the_field_leaves_the_plan_alone(): void
    {
        $trip = $this->trip(['meal_plan' => $this->plan()]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->adminPayload($trip, []))
            ->assertOk();

        $this->assertNotNull($trip->fresh()->meal_plan);
    }

    public function test_shortening_the_trip_trims_the_meal_table(): void
    {
        $trip = $this->trip(['meal_plan' => $this->plan()]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->adminPayload($trip, [
                'duration_days' => 1,
                'meal_plan' => $this->plan(),
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.meals.days');
    }

    public function test_bad_status_is_rejected(): void
    {
        $trip = $this->trip();
        $plan = $this->plan();
        $plan['days'][0]['lunch']['status'] = 'maybe';

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->adminPayload($trip, ['meal_plan' => $plan]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meal_plan.days.0.lunch.status');
    }

    public function test_copying_the_plan_to_other_trips_fits_each_trip_length(): void
    {
        $oneDay = $this->trip(['duration_days' => 1]);
        $twoDays = $this->trip();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/trips/bulk-update-field', [
                'trip_ids' => [$oneDay->id, $twoDays->id],
                'field' => 'meal_plan',
                'value' => $this->plan(),
            ])
            ->assertOk();

        $this->assertCount(1, $oneDay->fresh()->meal_plan['days']);
        $this->assertCount(2, $twoDays->fresh()->meal_plan['days']);
    }

    public function test_copying_an_empty_plan_clears_other_trips(): void
    {
        $trip = $this->trip(['meal_plan' => $this->plan()]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/trips/bulk-update-field', [
                'trip_ids' => [$trip->id],
                'field' => 'meal_plan',
                'value' => null,
            ])
            ->assertOk();

        $this->assertNull($trip->fresh()->meal_plan);
    }

    public function test_guess_fills_the_table_from_the_itinerary(): void
    {
        $itinerary = [[
            'sector' => 'กำหนดการ',
            'items' => [
                ['day' => 1, 'title' => 'ออกเดินทาง', 'description' => "12:00 แวะรับประทานอาหารกลางวัน (อิสระ)\n18:00 อาหารเย็น หมูกระทะ"],
                ['day' => 2, 'title' => 'พิชิตยอด', 'description' => "06:00 อาหารเช้าและกลางวัน\nงดอาหารเย็นก่อนเดินทางกลับ"],
                ['day' => 3, 'title' => 'เกินจำนวนวัน', 'description' => 'อาหารเช้า'],
            ],
        ]];

        $result = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/trips/meal-plan/guess', [
                'itinerary' => $itinerary,
                'duration_days' => 2,
            ])
            ->assertOk()
            ->json('data');

        $days = $result['meal_plan']['days'];
        $this->assertCount(2, $days);
        $this->assertSame('none', $days[0]['breakfast']['status']);
        $this->assertSame('self', $days[0]['lunch']['status']);
        $this->assertSame('included', $days[0]['dinner']['status']);
        $this->assertSame('included', $days[1]['breakfast']['status']);
        $this->assertSame('included', $days[1]['lunch']['status']);
        // "งดอาหารเย็น" ไม่ใช่มื้อ
        $this->assertSame('none', $days[1]['dinner']['status']);
        $this->assertSame(4, $result['found']);
    }

    public function test_guess_does_not_mistake_morning_for_breakfast(): void
    {
        $result = TripMealPlan::guess([
            ['day' => 1, 'title' => 'ออกเดินทางตอนเช้า', 'description' => 'ถึงที่พักช่วงเย็น'],
        ], 1);

        $this->assertSame(0, $result['found']);
    }

    public function test_customers_cannot_call_the_guess_endpoint(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/admin/trips/meal-plan/guess', ['duration_days' => 1])
            ->assertForbidden();
    }

    // ── หน้าทริป ──────────────────────────────────────────────────────────

    public function test_public_trip_page_carries_the_meals(): void
    {
        $trip = $this->trip(['meal_plan' => $this->plan()]);

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.meals.label', 'อาหาร 3 มื้อ')
            ->assertJsonPath('data.meals.days.0.meals.2.status', 'included')
            ->assertJsonPath('data.meals.days.0.meals.2.note', 'หมูกระทะ');
    }

    public function test_trip_without_a_plan_sends_null(): void
    {
        $trip = $this->trip();

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.meals', null)
            ->assertJsonPath('data.meal_plan', null);
    }

    // ── ใบเดินทาง ─────────────────────────────────────────────────────────

    private function booking(TripSchedule $schedule): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3200,
            'paid_amount' => 3200,
        ]);
    }

    public function test_trip_brief_page_and_email_list_the_meals_with_dates(): void
    {
        $booking = $this->booking($this->schedule($this->trip(['meal_plan' => $this->plan()])));

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertSee('มื้ออาหาร')
            ->assertSee('รวมอาหาร 3 มื้อ · จ่ายเอง 1 มื้อ')
            ->assertSee('13 ก.ย. 2569')
            ->assertSee('เตรียมเงินสำหรับมื้อที่จ่ายเองไว้ด้วยนะครับ (1 มื้อ)', false);

        $html = (new TripBriefMail($booking->fresh()))->render();
        $this->assertStringContainsString('รวมอาหาร 3 มื้อ · จ่ายเอง 1 มื้อ', $html);
        $this->assertStringContainsString('หมูกระทะ', $html);
    }

    public function test_trip_brief_hides_the_card_when_the_trip_has_no_plan(): void
    {
        $booking = $this->booking($this->schedule($this->trip()));

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertDontSee('มื้ออาหาร');
    }

    public function test_trips_without_a_plan_keep_their_old_brief_digest(): void
    {
        // ใบเดินทางที่ส่งไปแล้วเก็บลายนิ้วมือไว้ — ถ้าคีย์ใหม่ทำให้มันเปลี่ยน
        // ทุกคนที่ได้ใบไปแล้วจะได้ "อัปเดต" ทั้งที่ไม่มีอะไรเปลี่ยน
        $booking = $this->booking($this->schedule($this->trip()));
        $briefs = app(TripBriefService::class);
        $payload = $briefs->payload($booking);

        $legacy = sha1(json_encode([
            Arr::except($payload['when'], ['days_left', 'countdown_label']),
            $payload['pickup'],
            $payload['meetup'],
            $payload['vehicle'],
            $payload['crew'],
            $payload['itinerary'],
            $payload['announcements'],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame($legacy, $briefs->digest($booking));
    }

    public function test_changing_the_meals_counts_as_a_brief_update(): void
    {
        $trip = $this->trip(['meal_plan' => $this->plan()]);
        $booking = $this->booking($this->schedule($trip));
        $before = app(TripBriefService::class)->digest($booking);

        $plan = $this->plan();
        $plan['days'][1]['dinner']['status'] = 'included';
        $trip->update(['meal_plan' => $plan]);

        $this->assertNotSame($before, app(TripBriefService::class)->digest($booking->fresh()));
    }

    // ── ห้องแชท ───────────────────────────────────────────────────────────

    private function ask(TripSchedule $schedule, string $body): ChatMessage
    {
        return ChatMessage::create([
            'schedule_id' => $schedule->id,
            'user_id' => User::factory()->create()->id,
            'sender_role' => 'customer',
            'body' => $body,
        ]);
    }

    private function answer(ChatMessage $question): void
    {
        (new PostChatAutoAnswerJob($question->id))->handle(app(ChatAutoAnswerService::class));
    }

    private function systemBodies(TripSchedule $schedule): array
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('sender_role', 'system')
            ->pluck('body')
            ->all();
    }

    public function test_meal_questions_are_recognised(): void
    {
        $auto = app(ChatAutoAnswerService::class);

        $this->assertSame('meals', $auto->topicFor('ทริปนี้อาหารกี่มื้อครับ'));
        $this->assertSame('meals', $auto->topicFor('มีอาหาร ไหมคะ'));
        $this->assertSame('meals', $auto->topicFor('มื้อเย็นวันแรกกินที่ไหนคะ'));
        $this->assertSame('meals', $auto->topicFor('อาหารเช้ากี่โมงครับ'));
    }

    public function test_food_talk_that_is_not_a_meal_question_is_left_alone(): void
    {
        $auto = app(ChatAutoAnswerService::class);

        // ไม่ใช่คำถาม
        $this->assertNull($auto->topicFor('โอนค่าอาหารแล้วครับ'));
        $this->assertNull($auto->topicFor('มื้อเย็นอร่อยมากครับ'));
        // รอบสั่งอาหารในห้อง / เรื่องแพ้อาหารมีเครื่องมือของมันเอง
        $this->assertNull($auto->topicFor('สั่งอาหารเย็นยังไงคะ'));
        $this->assertNull($auto->topicFor('แพ้อาหารทะเลต้องแจ้งไหมคะ'));
        // คำถามเดิมยังไปข้อเดิม
        $this->assertSame('time', $auto->topicFor('ขึ้นรถกี่โมงครับ'));
    }

    public function test_bot_answers_meal_questions_from_the_plan(): void
    {
        Bus::fake();
        $schedule = $this->schedule($this->trip(['meal_plan' => $this->plan()]));

        $this->answer($this->ask($schedule, 'อาหารกี่มื้อคะ'));

        $body = collect($this->systemBodies($schedule))->last();
        $this->assertStringContainsString('🍽️ มื้ออาหาร: รวมอาหาร 3 มื้อ · จ่ายเอง 1 มื้อ', $body);
        $this->assertStringContainsString('• วันที่ 1 (12 ก.ย. 2569): กลางวัน – แวะร้านริมทาง (จ่ายเอง) · เย็น – หมูกระทะ', $body);
        $this->assertStringContainsString('หมายเหตุ: มีเมนูมังสวิรัติ', $body);
        $this->assertTrue(ChatMessage::where('system_key', 'like', 'auto_answer_meals_%')->exists());
    }

    public function test_bot_stays_quiet_when_the_trip_has_no_plan(): void
    {
        Bus::fake();
        $schedule = $this->schedule($this->trip(['inclusions' => ['อาหาร 3 มื้อ']]));

        $this->answer($this->ask($schedule, 'อาหารกี่มื้อคะ'));

        $this->assertSame([], $this->systemBodies($schedule));
    }

    public function test_bot_answers_meals_once_a_day(): void
    {
        Bus::fake();
        $schedule = $this->schedule($this->trip(['meal_plan' => $this->plan()]));

        $this->answer($this->ask($schedule, 'อาหารกี่มื้อคะ'));
        $this->answer($this->ask($schedule, 'มีอาหารไหมครับ'));

        $this->assertCount(1, $this->systemBodies($schedule));
    }

    public function test_chat_room_flags_whether_the_meal_chip_has_an_answer(): void
    {
        Bus::fake();
        $withPlan = $this->schedule($this->trip(['meal_plan' => $this->plan()]));
        $withoutPlan = $this->schedule($this->trip());
        $user = User::factory()->create();
        foreach ([$withPlan, $withoutPlan] as $schedule) {
            $booking = $this->booking($schedule);
            $booking->update(['user_id' => $user->id]);
            BookingPassenger::create([
                'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี', 'phone' => '0812345678',
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$withPlan->id}/chat/room")
            ->assertOk()
            ->assertJsonPath('data.has_meals', true);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$withoutPlan->id}/chat/room")
            ->assertOk()
            ->assertJsonPath('data.has_meals', false);
    }

    public function test_chat_trip_info_carries_the_round_dated_meals(): void
    {
        $schedule = $this->schedule($this->trip(['meal_plan' => $this->plan()]));

        $info = app(TripFactsService::class)->forUser(User::factory()->create(), $schedule);

        $this->assertSame('รวมอาหาร 3 มื้อ · จ่ายเอง 1 มื้อ', $info['meals']['summary']);
        $this->assertSame('12 ก.ย. 2569', $info['meals']['days'][0]['date_label']);
    }
}
