<?php

namespace Tests\Feature;

use App\Jobs\ProcessStayBookingsJob;
use App\Models\SmartNotification;
use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\StayExperience;
use App\Models\StayRoomType;
use App\Models\Trip;
use App\Models\User;
use App\Services\SlipOcrService;
use App\Services\StayBookingService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StayBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::slipDisk());
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('operator', 'web');
        Role::findOrCreate('finance', 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function stay(array $overrides = []): Stay
    {
        return Stay::create(array_merge([
            'name' => 'บ้านดินริมดอย',
            'slug' => 'baan-din-'.uniqid(),
            'type' => 'homestay',
            'community_name' => 'ชุมชนบ้านแม่กลางหลวง',
            'province' => 'เชียงใหม่',
            'address' => '99 หมู่ 5',
            'host_name' => 'ลุงคำ',
            'host_phone' => '0811111111',
            'booking_mode' => Stay::MODE_INSTANT,
            'cancellation_policy' => 'moderate',
            'commission_percent' => 10,
            'status' => Stay::STATUS_ACTIVE,
            'host_token' => Stay::newHostToken(),
            'amenities' => ['wifi', 'local_food', 'not-a-real-key'],
        ], $overrides));
    }

    private function room(Stay $stay, array $overrides = []): StayRoomType
    {
        return $stay->roomTypes()->create(array_merge([
            'name' => 'ห้องคู่วิวนา',
            'units' => 2,
            'base_guests' => 2,
            'max_guests' => 3,
            'price_per_night' => 1000,
            'weekend_price' => 1500,
            'extra_guest_price' => 300,
        ], $overrides));
    }

    /** วันจันทร์ที่ไกลพอจะยกเลิกได้คืนเต็ม */
    private function monday(int $weeksAhead = 4): Carbon
    {
        return now('Asia/Bangkok')->startOfDay()->addWeeks($weeksAhead)->startOfWeek(Carbon::MONDAY);
    }

    private function payload(StayRoomType $room, Carbon $in, int $nights = 2, array $overrides = []): array
    {
        return array_merge([
            'room_type_id' => $room->id,
            'check_in' => $in->toDateString(),
            'check_out' => $in->copy()->addDays($nights)->toDateString(),
            'units' => 1,
            'guests' => 2,
            'guest_name' => 'สมหญิง ใจดี',
            'guest_phone' => '0899999999',
        ], $overrides);
    }

    private function book(User $user, Stay $stay, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/v1/stays/{$stay->slug}/bookings", $payload);
    }

    private function fakeOcr(string $status): void
    {
        $this->mock(SlipOcrService::class, function ($mock) use ($status) {
            $mock->shouldReceive('verify')->andReturn(['status' => $status, 'reason' => 'test', 'raw' => ['amount' => 1]]);
        });
    }

    private function slip(User $user, string $ref)
    {
        return $this->actingAs($user, 'sanctum')->post("/api/v1/stay-bookings/{$ref}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json']);
    }

    // ── สาธารณะ ─────────────────────────────────────────────────────

    public function test_public_list_shows_only_active_stays_and_hides_host_secrets(): void
    {
        $active = $this->stay();
        $this->room($active);
        $this->stay(['name' => 'ร่าง', 'status' => Stay::STATUS_DRAFT]);

        $res = $this->getJson('/api/v1/stays')->assertOk();
        $this->assertCount(1, $res->json('data.stays'));
        $this->assertSame(90.0, (float) $res->json('data.stays.0.community_share_percent'));
        $this->assertSame(1000.0, (float) $res->json('data.stays.0.price_from'));
        // คีย์ที่ไม่รู้จักถูกกรองทิ้ง
        $this->assertSame(['wifi', 'local_food'], array_column($res->json('data.stays.0.amenities'), 'key'));

        $detail = $this->getJson("/api/v1/stays/{$active->slug}")->assertOk();
        $body = json_encode($detail->json());
        $this->assertStringNotContainsString($active->host_token, $body);
        $this->assertStringNotContainsString('0811111111', $body);
        $this->assertNotEmpty($detail->json('data.cancellation_policy.lines'));
    }

    public function test_trip_page_lists_linked_stays_with_distance_note(): void
    {
        $trip = Trip::create([
            'title' => 'ดอยอินทนนท์', 'slug' => 'doi-'.uniqid(), 'type' => 'trekking', 'location' => 'เชียงใหม่',
            'difficulty' => 'easy', 'duration_days' => 2, 'max_participants' => 10, 'price_per_person' => 2000, 'status' => 'active',
        ]);
        $stay = $this->stay();
        $this->room($stay);
        $stay->trips()->attach($trip->id, ['distance_note' => '15 นาทีจากจุดเริ่มเดิน']);

        $this->getJson("/api/v1/trips/{$trip->slug}/stays")
            ->assertOk()
            ->assertJsonPath('data.0.distance_note', '15 นาทีจากจุดเริ่มเดิน');

        $this->getJson('/api/v1/stays?trip='.$trip->slug)->assertJsonCount(1, 'data.stays');
    }

    public function test_quote_uses_weekend_price_extra_guests_experiences_and_commission(): void
    {
        $stay = $this->stay();
        $room = $this->room($stay);
        $exp = StayExperience::create(['stay_id' => $stay->id, 'title' => 'ทำข้าวหลาม', 'price_per_person' => 200]);

        // พฤหัส → อาทิตย์ = คืนพฤหัส(1000) ศุกร์(1500) เสาร์(1500)
        $thu = $this->monday()->addDays(3);
        $res = $this->postJson("/api/v1/stays/{$stay->slug}/quote", $this->payload($room, $thu, 3, [
            'guests' => 3,
            'experiences' => [['id' => $exp->id]],
        ]))->assertOk();

        $this->assertSame(4000.0, (float) $res->json('data.room_amount'));
        // คนที่ 3 × 300 × 3 คืน
        $this->assertSame(900.0, (float) $res->json('data.extra_guest_amount'));
        // กิจกรรมคิดตามจำนวนคนถ้าไม่ระบุ
        $this->assertSame(600.0, (float) $res->json('data.experiences_amount'));
        $this->assertSame(5500.0, (float) $res->json('data.total_amount'));
        $this->assertSame(550.0, (float) $res->json('data.commission_amount'));
        $this->assertSame(4950.0, (float) $res->json('data.host_amount'));

        // เกินความจุ
        $this->postJson("/api/v1/stays/{$stay->slug}/quote", $this->payload($room, $thu, 1, ['guests' => 4]))
            ->assertStatus(422);
    }

    // ── จองทันที ─────────────────────────────────────────────────────

    public function test_instant_booking_holds_room_until_payment_deadline(): void
    {
        $stay = $this->stay();
        $room = $this->room($stay, ['units' => 1]);
        $in = $this->monday();

        $first = $this->book(User::factory()->create(), $stay, $this->payload($room, $in))->assertCreated();
        $this->assertSame(StayBooking::STATUS_AWAITING_PAYMENT, $first->json('data.status'));
        $this->assertTrue($first->json('data.can_pay'));

        // ห้องสุดท้ายถูกกันไว้ — คืนที่ทับกันจองไม่ได้
        $this->book(User::factory()->create(), $stay, $this->payload($room, $in->copy()->addDay()))
            ->assertStatus(422);
        // แต่คืนถัดจากวันออกยังว่าง
        $this->book(User::factory()->create(), $stay, $this->payload($room, $in->copy()->addDays(2)))
            ->assertCreated();

        // เลยเวลาจ่าย — ห้องกลับมาว่างทันทีแม้งานปิดใบยังไม่วิ่ง
        $this->travel(31)->minutes();
        $this->book(User::factory()->create(), $stay, $this->payload($room, $in))->assertCreated();

        (new ProcessStayBookingsJob)->handle(app(StayBookingService::class));
        $this->assertSame(StayBooking::STATUS_EXPIRED, StayBooking::where('ref', $first->json('data.ref'))->value('status'));
    }

    public function test_verified_slip_confirms_and_tells_host(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_VERIFIED);
        $host = User::factory()->create();
        $stay = $this->stay(['host_user_id' => $host->id]);
        $room = $this->room($stay);
        $user = User::factory()->create();

        $ref = $this->book($user, $stay, $this->payload($room, $this->monday()))->json('data.ref');

        // ยังไม่ยืนยัน — ที่อยู่/เบอร์เจ้าของยังไม่เปิด
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/stay-bookings/{$ref}")
            ->assertJsonPath('data.stay.host_phone', null);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/stay-bookings/{$ref}/payment")
            ->assertOk()->assertJsonPath('data.amount', 2000);

        $this->slip($user, $ref)->assertOk()->assertJsonPath('data.status', StayBooking::STATUS_CONFIRMED)
            ->assertJsonPath('data.stay.host_phone', '0811111111');

        $this->assertTrue(SmartNotification::where('user_id', $host->id)->where('type', 'stay_host_new_booking')->exists());
        $this->assertTrue(SmartNotification::where('user_id', $user->id)->where('type', 'stay_booking_confirmed')->exists());
    }

    public function test_unreadable_slip_waits_for_admin_who_can_approve_or_reject(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_FAILED);
        $admin = $this->admin();
        $stay = $this->stay();
        $room = $this->room($stay);
        $user = User::factory()->create();
        $ref = $this->book($user, $stay, $this->payload($room, $this->monday()))->json('data.ref');

        $this->slip($user, $ref)->assertOk()->assertJsonPath('data.status', StayBooking::STATUS_PAYMENT_REVIEW);
        $this->assertTrue(SmartNotification::where('user_id', $admin->id)->where('type', 'stay_slip_review')->exists());

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stay-bookings/{$ref}/reject-slip", ['note' => 'ยอดไม่ตรง'])
            ->assertOk()->assertJsonPath('data.status', StayBooking::STATUS_AWAITING_PAYMENT);

        $this->slip($user, $ref)->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stay-bookings/{$ref}/confirm-payment")
            ->assertOk()->assertJsonPath('data.status', StayBooking::STATUS_CONFIRMED)
            ->assertJsonPath('data.slip_ocr_status', SlipOcrService::STATUS_APPROVED);
    }

    // ── ส่งคำขอ + หน้าเจ้าของ ─────────────────────────────────────────

    public function test_request_mode_waits_for_host_link_and_does_not_hold_room(): void
    {
        $host = User::factory()->create();
        $stay = $this->stay(['booking_mode' => Stay::MODE_REQUEST, 'host_user_id' => $host->id]);
        $room = $this->room($stay, ['units' => 1]);
        $in = $this->monday();

        $a = $this->book(User::factory()->create(), $stay, $this->payload($room, $in))->assertCreated();
        $this->assertSame(StayBooking::STATUS_REQUESTED, $a->json('data.status'));
        $this->assertFalse($a->json('data.can_pay'));
        $this->assertTrue(SmartNotification::where('user_id', $host->id)->where('type', 'stay_host_new_request')->exists());

        // คำขอไม่กันห้อง — อีกคนขอวันเดียวกันได้
        $b = $this->book(User::factory()->create(), $stay, $this->payload($room, $in))->assertCreated();

        $base = "/api/v1/host/stays/{$stay->host_token}";
        $overview = $this->getJson($base)->assertOk();
        $this->assertCount(2, $overview->json('data.requests'));

        $this->postJson("{$base}/bookings/{$a->json('data.ref')}/approve")
            ->assertOk()->assertJsonPath('data.status', StayBooking::STATUS_AWAITING_PAYMENT);

        // ห้องเดียวถูกกันให้ใบแรกแล้ว — ยืนยันใบที่สองไม่ได้
        $this->postJson("{$base}/bookings/{$b->json('data.ref')}/approve")->assertStatus(422);
        $this->postJson("{$base}/bookings/{$b->json('data.ref')}/decline", ['reason' => 'เต็มแล้วค่ะ'])
            ->assertOk()->assertJsonPath('data.status', StayBooking::STATUS_DECLINED);

        $declined = StayBooking::where('ref', $b->json('data.ref'))->first();
        $this->assertTrue(SmartNotification::where('user_id', $declined->user_id)->where('type', 'stay_request_declined')->exists());

        // ลิงก์ผิด
        $this->getJson('/api/v1/host/stays/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_staff_can_approve_request_with_negotiated_total(): void
    {
        $admin = $this->admin();
        $stay = $this->stay(['booking_mode' => Stay::MODE_REQUEST]);
        $room = $this->room($stay);
        $ref = $this->book(User::factory()->create(), $stay, $this->payload($room, $this->monday()))->json('data.ref');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stay-bookings/{$ref}/approve", ['total_amount' => 1800])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 1800)
            ->assertJsonPath('data.host_amount', 1620);
    }

    public function test_stale_requests_expire(): void
    {
        $stay = $this->stay(['booking_mode' => Stay::MODE_REQUEST]);
        $room = $this->room($stay);
        $ref = $this->book(User::factory()->create(), $stay, $this->payload($room, $this->monday()))->json('data.ref');

        $this->travel(49)->hours();
        (new ProcessStayBookingsJob)->handle(app(StayBookingService::class));

        $this->assertSame(StayBooking::STATUS_EXPIRED, StayBooking::where('ref', $ref)->value('status'));
    }

    public function test_host_can_close_rooms_and_set_special_price(): void
    {
        $stay = $this->stay();
        $room = $this->room($stay, ['units' => 2]);
        $in = $this->monday();
        $base = "/api/v1/host/stays/{$stay->host_token}";

        $this->postJson("{$base}/nights", [
            'room_type_id' => $room->id,
            'from' => $in->toDateString(),
            'to' => $in->toDateString(),
            'closed_units' => 2,
        ])->assertOk();

        $avail = $this->getJson("/api/v1/stays/{$stay->slug}/availability?check_in={$in->toDateString()}&check_out={$in->copy()->addDay()->toDateString()}&guests=2");
        $this->assertSame(0, $avail->json('data.rooms.0.available_units'));
        $this->book(User::factory()->create(), $stay, $this->payload($room, $in, 1))->assertStatus(422);

        // เปิดคืน + ตั้งราคาพิเศษคืนถัดไป
        $this->postJson("{$base}/nights", ['room_type_id' => $room->id, 'from' => $in->toDateString(), 'to' => $in->toDateString(), 'closed_units' => 0])->assertOk();
        $this->postJson("{$base}/nights", ['room_type_id' => $room->id, 'from' => $in->copy()->addDay()->toDateString(), 'to' => $in->copy()->addDay()->toDateString(), 'price' => 700])->assertOk();

        $cal = $this->getJson("{$base}/calendar?month=".$in->format('Y-m'))->assertOk();
        $days = collect($cal->json('data.rooms.0.days'))->keyBy('date');
        $this->assertSame(2, $days[$in->toDateString()]['available']);
        $this->assertSame(700.0, (float) $days[$in->copy()->addDay()->toDateString()]['price']);

        $res = $this->book(User::factory()->create(), $stay, $this->payload($room, $in, 2))->assertCreated();
        $this->assertSame(1700.0, (float) $res->json('data.total_amount'));
    }

    // ── ยกเลิก / คืนเงิน ─────────────────────────────────────────────

    public function test_customer_cancel_refunds_by_policy_and_needs_account(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_VERIFIED);
        $stay = $this->stay(['cancellation_policy' => 'moderate']);
        $room = $this->room($stay);
        $user = User::factory()->create();
        // 4 วันก่อนเข้าพัก = moderate คืน 50%
        $in = now('Asia/Bangkok')->startOfDay()->addDays(4);
        $ref = $this->book($user, $stay, $this->payload($room, $in, 1))->json('data.ref');
        $this->slip($user, $ref)->assertOk();
        $total = (float) StayBooking::where('ref', $ref)->value('total_amount');

        $preview = $this->actingAs($user, 'sanctum')->getJson("/api/v1/stay-bookings/{$ref}/cancel-preview")->assertOk();
        $this->assertSame(50, $preview->json('data.refund_percent'));
        $this->assertSame($total / 2, (float) $preview->json('data.refund_amount'));

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/stay-bookings/{$ref}/cancel")->assertStatus(422);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/stay-bookings/{$ref}/cancel", [
            'refund_account' => ['bank' => 'กสิกรไทย', 'number' => '1234567890', 'name' => 'สมหญิง ใจดี'],
        ])->assertOk()->assertJsonPath('data.status', StayBooking::STATUS_CANCELLED)
            ->assertJsonPath('data.refund_status', StayBooking::REFUND_PENDING);

        $booking = StayBooking::where('ref', $ref)->first();
        // ส่วนที่ไม่คืนยังแบ่งให้ชุมชน 90%
        $this->assertSame(round($total / 2 * 0.9, 2), (float) $booking->host_amount);
        $this->assertSame('1234567890', $booking->refund_account['number']);

        // ยังจ่ายเจ้าของไม่ได้จนกว่าจะคืนเงินลูกค้าเสร็จ
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/stays/{$stay->id}/payouts")->assertJsonPath('data.pending_amount', 0);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stay-bookings/{$ref}/refunded", ['note' => 'โอนแล้ว'])
            ->assertOk()->assertJsonPath('data.refund_status', StayBooking::REFUND_REFUNDED);
        $this->assertSame(round($total / 2 * 0.9, 2), (float) $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/stays/{$stay->id}/payouts")->json('data.pending_amount'));
    }

    public function test_staff_cancel_refunds_in_full_and_host_gets_nothing(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_VERIFIED);
        $admin = $this->admin();
        $stay = $this->stay();
        $room = $this->room($stay);
        $user = User::factory()->create();
        $ref = $this->book($user, $stay, $this->payload($room, $this->monday()))->json('data.ref');
        $this->slip($user, $ref);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stay-bookings/{$ref}/cancel", ['reason' => 'น้ำป่าไหลหลาก'])
            ->assertOk()
            ->assertJsonPath('data.refund_amount', 2000)
            ->assertJsonPath('data.host_amount', 0);

        // ลูกค้าแจ้งบัญชีทีหลังได้
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/stay-bookings/{$ref}/refund-account", [
            'bank' => 'พร้อมเพย์', 'number' => '0899999999', 'name' => 'สมหญิง',
        ])->assertOk()->assertJsonPath('data.refund_account_set', true);
    }

    // ── หลังเข้าพัก: จบงาน รีวิว จ่ายเจ้าของ ───────────────────────────

    public function test_finished_stay_completes_invites_review_and_pays_host(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_VERIFIED);
        $admin = $this->admin();
        $admin->assignRole('finance');
        $host = User::factory()->create();
        $stay = $this->stay(['host_user_id' => $host->id]);
        $room = $this->room($stay);
        $user = User::factory()->create();
        $in = $this->monday(1);
        $ref = $this->book($user, $stay, $this->payload($room, $in))->json('data.ref');
        $this->slip($user, $ref);

        // รีวิวก่อนเข้าพักไม่ได้
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/stay-bookings/{$ref}/review", ['rating' => 5])->assertStatus(422);

        Carbon::setTestNow($in->copy()->addDays(2)->setTime(12, 0));
        (new ProcessStayBookingsJob)->handle(app(StayBookingService::class));

        $this->assertSame(StayBooking::STATUS_COMPLETED, StayBooking::where('ref', $ref)->value('status'));
        $this->assertTrue(SmartNotification::where('user_id', $user->id)->where('type', 'stay_review_invite')->exists());

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/stay-bookings/{$ref}/review", ['rating' => 4, 'comment' => 'อาหารอร่อยมาก'])
            ->assertOk()->assertJsonPath('data.can_review', false);
        $this->assertSame(4.0, (float) $stay->fresh()->rating_avg);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/stay-bookings/{$ref}/review", ['rating' => 5])->assertStatus(422);

        // เจ้าของตอบรีวิวได้จากลิงก์
        $reviewId = $stay->reviews()->value('id');
        $this->postJson("/api/v1/host/stays/{$stay->host_token}/reviews/{$reviewId}/reply", ['reply' => 'ขอบคุณค่ะ'])->assertOk();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/stays/{$stay->id}/payouts")
            ->assertJsonPath('data.pending_amount', 1800);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stays/{$stay->id}/payouts", ['reference' => 'KBANK-1'])
            ->assertOk()->assertJsonPath('data.amount', 1800);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stays/{$stay->id}/payouts")->assertStatus(422);

        $this->assertTrue(SmartNotification::where('user_id', $host->id)->where('type', 'stay_payout')->exists());
        $this->getJson('/api/v1/stays')->assertJsonPath('data.impact.paid_to_communities', 1800);
    }

    public function test_operator_without_finance_cannot_pay_host(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operator');
        $stay = $this->stay();

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/stays/{$stay->id}/payouts")->assertForbidden();
    }

    // ── สิทธิ์ / หลังบ้าน ────────────────────────────────────────────

    public function test_customers_cannot_see_each_others_bookings(): void
    {
        $stay = $this->stay();
        $room = $this->room($stay);
        $ref = $this->book(User::factory()->create(), $stay, $this->payload($room, $this->monday()))->json('data.ref');

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/stay-bookings/{$ref}")->assertNotFound();
    }

    public function test_paused_stay_cannot_be_booked(): void
    {
        $stay = $this->stay(['status' => Stay::STATUS_PAUSED]);
        $room = $this->room($stay);

        $this->book(User::factory()->create(), $stay, $this->payload($room, $this->monday()))->assertStatus(422);
        $this->getJson("/api/v1/stays/{$stay->slug}")->assertNotFound();
    }

    public function test_admin_builds_a_stay_and_cannot_open_it_without_rooms(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/stays', [
            'name' => 'โฮมสเตย์บ้านนาต้นจั่น',
            'province' => 'สุโขทัย',
            'amenities' => ['wifi'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->assertNotNull(Stay::find($id)->host_token);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/stays/{$id}", ['status' => 'active'])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stays/{$id}/rooms", [
            'name' => 'ห้องรวม', 'units' => 1, 'base_guests' => 4, 'max_guests' => 6, 'price_per_night' => 1200,
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/stays/{$id}", [
            'status' => 'active',
            'payout_account' => ['bank' => 'ธ.ก.ส.', 'number' => '111', 'name' => 'กลุ่มวิสาหกิจ'],
        ])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.payout_account.bank', 'ธ.ก.ส.');

        $old = Stay::find($id)->host_token;
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/stays/{$id}/host-token")->assertOk();
        $this->getJson("/api/v1/host/stays/{$old}")->assertNotFound();

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/admin/stays')->assertForbidden();
    }

    public function test_deleting_a_stay_with_bookings_pauses_it_instead(): void
    {
        $admin = $this->admin();
        $stay = $this->stay();
        $room = $this->room($stay);
        $this->book(User::factory()->create(), $stay, $this->payload($room, $this->monday()))->assertCreated();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/stays/{$stay->id}")->assertOk();
        $this->assertSame(Stay::STATUS_PAUSED, $stay->fresh()->status);
    }

    public function test_shared_stay_link_unfurls_with_the_stay_and_is_in_the_sitemap(): void
    {
        $stay = $this->stay(['name' => 'บ้านไม้ริมธาร', 'summary' => 'ตื่นมาเจอทะเลหมอก']);
        $this->room($stay);

        $this->get('/stays/'.$stay->slug)->assertOk()->assertSee('บ้านไม้ริมธาร', false)->assertSee('ตื่นมาเจอทะเลหมอก', false);
        $this->get('/sitemap.xml')->assertOk()->assertSee('/stays/'.$stay->slug, false);
    }

    public function test_admin_summary_counts_the_work_waiting_for_the_team(): void
    {
        $admin = $this->admin();
        $requestStay = $this->stay(['booking_mode' => Stay::MODE_REQUEST]);
        $requestRoom = $this->room($requestStay);
        $instantStay = $this->stay();
        $instantRoom = $this->room($instantStay);

        // คำขอรอตอบ 1 ใบ
        $this->book(User::factory()->create(), $requestStay, $this->payload($requestRoom, $this->monday()))->assertCreated();
        // จ่ายแล้ว (นับเป็นรายได้เดือนนี้) 1 ใบ
        $paidRef = $this->book(User::factory()->create(), $instantStay, $this->payload($instantRoom, $this->monday()))->json('data.ref');
        app(StayBookingService::class)->confirmPayment(StayBooking::where('ref', $paidRef)->first());

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/stay-bookings/summary')
            ->assertOk()
            ->assertJsonPath('data.requested', 1)
            ->assertJsonPath('data.attention', 1)
            ->assertJsonPath('data.upcoming', 1)
            ->assertJsonPath('data.active_stays', 2)
            ->assertJsonPath('data.month.bookings', 1)
            ->assertJsonPath('data.month.room_nights', 2);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/stays')
            ->assertOk()
            ->assertJsonPath('data.stays.0.active_rooms_count', 1);

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/admin/stay-bookings/summary')->assertForbidden();
    }

    public function test_admin_booking_detail_carries_the_host_link_and_can_be_filtered_by_stay(): void
    {
        $admin = $this->admin();
        $stay = $this->stay();
        $room = $this->room($stay);
        $other = $this->stay();
        $otherRoom = $this->room($other);

        $ref = $this->book(User::factory()->create(), $stay, $this->payload($room, $this->monday()))->json('data.ref');
        $this->book(User::factory()->create(), $other, $this->payload($otherRoom, $this->monday()))->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/stay-bookings/{$ref}")
            ->assertOk()
            ->assertJsonPath('data.host_url', $stay->fresh()->hostUrl())
            ->assertJsonPath('data.beam_payments', []);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/stay-bookings?stay_id={$stay->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ref', $ref);
    }
}
