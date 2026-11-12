<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ติดตามการคืนเงินทีละขั้น (RefundService):
 * ยกเลิกทั้งรอบ → ลูกค้ากรอกบัญชีในแอป → ทีมงานเริ่มโอน → โอนแล้วพร้อมสลิป
 * พร้อม push ทุกขั้น ตีกลับบัญชีที่ผิด และเตือนคนที่ยังไม่กรอก
 */
class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว', 'slug' => 'refund-flow-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 5000, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(20)->toDateString(),
            'return_date' => now()->addDays(21)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 3,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => 'LLK-RF-'.strtoupper(uniqid()),
            'user_id' => User::factory()->create(['phone' => '0812345678'])->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'payment_type' => 'deposit',
            'total_amount' => 5000,
            'paid_amount' => 1500,
        ], $overrides));
    }

    private function cancelRound(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$this->schedule->id}/cancel-refund", [
                'reason' => 'อุทยานประกาศปิดพื้นที่',
            ])
            ->assertOk();
    }

    public function test_cancelling_a_round_opens_a_full_refund_for_every_paid_booking(): void
    {
        $deposit = $this->makeBooking();
        $full = $this->makeBooking(['payment_type' => 'full', 'paid_amount' => 5000]);
        $unpaid = $this->makeBooking(['status' => 'pending', 'paid_amount' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/schedules/{$this->schedule->id}/cancel-refund")
            ->assertOk()
            ->assertJsonPath('data.bookings', 3)
            ->assertJsonPath('data.paid_bookings', 2)
            ->assertJsonPath('data.refund_total', 6500);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$this->schedule->id}/cancel-refund", [
                'reason' => 'อุทยานประกาศปิดพื้นที่',
            ])
            ->assertOk()
            ->assertJsonPath('data.cancelled', 3)
            ->assertJsonPath('data.refunds', 2)
            ->assertJsonPath('data.refund_total', 6500);

        $this->assertSame('cancelled', $this->schedule->fresh()->status);

        // มัดจำก็คืน — เราเป็นฝ่ายยกเลิก
        $deposit->refresh();
        $this->assertSame('cancelled', $deposit->status);
        $this->assertSame(RefundService::AWAITING_ACCOUNT, $deposit->refund_status);
        $this->assertSame(1500.0, (float) $deposit->refund_amount);
        $this->assertTrue($deposit->refund_full);
        $this->assertNotNull($deposit->refund_opened_at);
        $this->assertSame(5000.0, (float) $full->fresh()->refund_amount);

        $unpaid->refresh();
        $this->assertSame('cancelled', $unpaid->status);
        $this->assertNull($unpaid->refund_status);

        $push = SmartNotification::where('user_id', $deposit->user_id)->where('type', 'refund_account_needed')->first();
        $this->assertNotNull($push);
        $this->assertSame('ทริปถูกยกเลิก · กรอกบัญชีรับเงินคืน', $push->title);
        $this->assertSame('refund', $push->data['route']);
        $this->assertTrue(SmartNotification::where('user_id', $unpaid->user_id)->where('type', 'booking_cancelled')->exists());

        $this->assertTrue(SmsLog::where('booking_id', $deposit->id)->where('sms_type', 'refund_opened')->exists());

        // ยกเลิกซ้ำไม่ได้
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$this->schedule->id}/cancel-refund", ['reason' => 'ซ้ำ'])
            ->assertStatus(422);
    }

    public function test_customer_sees_refund_tracking_and_submits_a_bank_account(): void
    {
        $booking = $this->makeBooking();
        $this->cancelRound();

        $owner = User::find($booking->user_id);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.refund.state', 'awaiting_account')
            ->assertJsonPath('data.refund.amount', 1500)
            ->assertJsonPath('data.refund.full', true)
            ->assertJsonPath('data.refund.can_edit_account', true)
            ->assertJsonPath('data.refund.account', null)
            ->assertJsonPath('data.refund.reason', 'อุทยานประกาศปิดพื้นที่');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'กสิกรไทย',
                'account_number' => '123-4-56789-0',
                'account_name' => 'สมชาย  ใจดี',
            ])
            ->assertOk()
            ->assertJsonPath('data.refund.state', 'requested')
            ->assertJsonPath('data.refund.account.number_masked', '••••7890')
            ->assertJsonPath('data.refund.account.name', 'สมชาย ใจดี')
            ->assertJsonMissingPath('data.refund_account');

        $booking->refresh();
        $this->assertSame(['bank' => 'กสิกรไทย', 'number' => '1234567890', 'name' => 'สมชาย ใจดี'], $booking->refund_account);
        $this->assertNotNull($booking->refund_account_at);

        // แก้บัญชีได้จนกว่าทีมงานจะเริ่มโอน
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'พร้อมเพย์', 'account_number' => '0812345678', 'account_name' => 'สมชาย ใจดี',
            ])
            ->assertOk()
            ->assertJsonPath('data.refund.account.bank', 'พร้อมเพย์');
    }

    public function test_account_is_validated_and_only_the_owner_can_submit_it(): void
    {
        $booking = $this->makeBooking();
        $this->cancelRound();
        $owner = User::find($booking->user_id);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'พร้อมเพย์', 'account_number' => '12345', 'account_name' => 'สมชาย',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลขบัตรประชาชน 13 หลัก');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'ธนาคารปลอม', 'account_number' => '1234567890', 'account_name' => 'สมชาย',
            ])
            ->assertStatus(422);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'กสิกรไทย', 'account_number' => '1234567890', 'account_name' => 'คนอื่น',
            ])
            ->assertNotFound();

        $this->assertSame(RefundService::AWAITING_ACCOUNT, $booking->fresh()->refund_status);
    }

    public function test_a_booking_without_a_refund_cannot_take_an_account(): void
    {
        $booking = $this->makeBooking();

        $this->actingAs(User::find($booking->user_id), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'กสิกรไทย', 'account_number' => '1234567890', 'account_name' => 'สมชาย',
            ])
            ->assertStatus(422);

        $this->actingAs(User::find($booking->user_id), 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertJsonPath('data.refund', null);
    }

    public function test_full_lifecycle_pushes_at_every_step_and_ends_refunded(): void
    {
        $booking = $this->makeBooking();
        $this->cancelRound();
        $owner = User::find($booking->user_id);

        // ยังไม่มีบัญชี — เริ่มโอนไม่ได้
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund/processing")
            ->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'ไทยพาณิชย์', 'account_number' => '1112223334', 'account_name' => 'สมชาย ใจดี',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund/processing")
            ->assertOk()
            ->assertJsonPath('data.refund.state', 'processing');

        $this->assertTrue(SmartNotification::where('user_id', $owner->id)->where('type', 'refund_processing')->exists());

        // กำลังโอนแล้ว ลูกค้าแก้บัญชีเองไม่ได้
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'กสิกรไทย', 'account_number' => '1234567890', 'account_name' => 'สมชาย ใจดี',
            ])
            ->assertStatus(422);

        // ยอดตั้งต้นของปุ่มคืนเงิน = เต็มจำนวน ไม่ใช่นโยบาย 80/50/0
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.refund_percent', 100)
            ->assertJsonPath('data.refund_amount', 1500)
            ->assertJsonPath('data.refund_status', 'processing')
            ->assertJsonPath('data.refund_account.number', '1112223334');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund", ['refund_amount' => 1500])
            ->assertOk();

        $booking->refresh();
        $this->assertSame('refunded', $booking->status);
        $this->assertSame(RefundService::REFUNDED, $booking->refund_status);

        $done = SmartNotification::where('user_id', $owner->id)->where('type', 'booking_refunded')->first();
        $this->assertSame('โอนเงินคืนแล้ว ฿1,500', $done->title);
        $this->assertStringContainsString('ไทยพาณิชย์ ••••3334', $done->body);
        $this->assertSame('refund', $done->data['route']);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertJsonPath('data.refund.state', 'refunded')
            ->assertJsonPath('data.refund.can_edit_account', false)
            ->assertJsonPath('data.refund.banks', []);
    }

    public function test_admin_can_bounce_a_wrong_account_back_to_the_customer(): void
    {
        $booking = $this->makeBooking();
        $this->cancelRound();
        $owner = User::find($booking->user_id);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'กรุงเทพ', 'account_number' => '1112223334', 'account_name' => 'ชื่อผิด',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund/reject-account", [
                'note' => 'ชื่อบัญชีไม่ตรงกับเลขบัญชี',
            ])
            ->assertOk()
            ->assertJsonPath('data.refund.state', 'awaiting_account')
            ->assertJsonPath('data.refund.note', 'ชื่อบัญชีไม่ตรงกับเลขบัญชี')
            ->assertJsonPath('data.refund.account', null);

        $push = SmartNotification::where('user_id', $owner->id)->where('type', 'refund_account_rejected')->first();
        $this->assertStringContainsString('ชื่อบัญชีไม่ตรงกับเลขบัญชี', $push->body);

        // กรอกใหม่แล้วข้อความตีกลับหายไป
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/refund/account", [
                'bank' => 'กรุงเทพ', 'account_number' => '1112223334', 'account_name' => 'สมชาย ใจดี',
            ])
            ->assertOk()
            ->assertJsonPath('data.refund.note', null);
    }

    public function test_admin_can_open_a_refund_for_a_single_confirmed_booking(): void
    {
        $booking = $this->makeBooking(['payment_type' => 'full', 'paid_amount' => 5000]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund/open", [
                'refund_amount' => 4000,
                'reason' => 'ลูกค้าป่วย ขอยกเลิก',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.refund.state', 'awaiting_account')
            ->assertJsonPath('data.refund.amount', 4000)
            ->assertJsonPath('data.refund.full', false);

        // คืนรายใบ รอบยังออกตามปกติ
        $this->assertSame('open', $this->schedule->fresh()->status);
        $this->assertTrue(SmartNotification::where('type', 'refund_account_needed')
            ->where('title', 'กรอกบัญชีเพื่อรับเงินคืน')->exists());

        // เปิดซ้ำไม่ได้ · เกินยอดที่จ่ายไม่ได้
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund/open", ['refund_amount' => 100])
            ->assertStatus(422);

        $other = $this->makeBooking();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$other->booking_ref}/refund/open", ['refund_amount' => 9999])
            ->assertStatus(422);
    }

    public function test_customers_without_an_account_are_reminded_on_day_two_and_five(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00', 'Asia/Bangkok'));
        $booking = $this->makeBooking();
        $this->cancelRound();
        $answered = $this->makeBooking(['status' => 'cancelled']);
        app(RefundService::class)->open($answered, 1500, notify: false);
        app(RefundService::class)->submitAccount($answered, ['bank' => 'กรุงไทย', 'number' => '1234567890', 'name' => 'ก']);

        $count = fn () => SmartNotification::where('type', 'refund_account_reminder')->count();

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'Asia/Bangkok'));
        $this->assertSame(0, app(RefundService::class)->remindMissingAccounts());

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'Asia/Bangkok'));
        $this->assertSame(1, app(RefundService::class)->remindMissingAccounts());

        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00', 'Asia/Bangkok'));
        $this->assertSame(0, app(RefundService::class)->remindMissingAccounts());

        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00', 'Asia/Bangkok'));
        $this->assertSame(1, app(RefundService::class)->remindMissingAccounts());

        $this->assertSame(2, $count());
        $this->assertSame(2, SmartNotification::where('user_id', $booking->user_id)->where('type', 'refund_account_reminder')->count());

        Carbon::setTestNow();
    }

    public function test_refund_requests_show_in_the_action_queue_by_stage(): void
    {
        $waiting = $this->makeBooking();
        $ready = $this->makeBooking();
        $this->cancelRound();

        app(RefundService::class)->submitAccount($ready->fresh(), ['bank' => 'ออมสิน', 'number' => '123456789012', 'name' => 'สมหญิง']);

        $groups = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk()
            ->json('data.groups'))->keyBy('key');

        $this->assertSame(1, $groups['refund_requests']['count']);
        $this->assertSame('/admin/bookings?refund_status=open', $groups['refund_requests']['route']);
        // เพิ่งเปิดเรื่อง ยังไม่ถึงเวลาตาม
        $this->assertSame(0, $groups['refund_awaiting_account']['count']);

        $waiting->refresh()->forceFill(['refund_opened_at' => now()->subDays(4)])->save();

        $groups = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')->json('data.groups'))->keyBy('key');
        $this->assertSame(1, $groups['refund_awaiting_account']['count']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/bookings?refund_status=open')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
