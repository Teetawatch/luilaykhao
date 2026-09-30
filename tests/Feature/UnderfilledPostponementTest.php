<?php

namespace Tests\Feature;

use App\Http\Resources\BookingResource;
use App\Jobs\SendForceMajeureRemindersJob;
use App\Mail\BookingCancelledMail;
use App\Mail\TripPostponedMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ForceMajeureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รอบที่ไม่ได้ออกเพราะผู้ร่วมทริปไม่ครบ → ลูกค้าเลือกรอบใหม่เอง หรือรับเงินคืนเต็มจำนวน
 * (กลไกเดียวกับเหตุสุดวิสัย แต่มีทางเลือกคืนเงิน และกำหนดตัดสินใจ 14 วัน)
 */
class UnderfilledPostponementTest extends TestCase
{
    use RefreshDatabase;

    private Trip $trip;

    private TripSchedule $thin;

    private User $admin;

    private const ACCOUNT = [
        'bank' => 'กสิกรไทย',
        'account_number' => '123-4-56789-0',
        'account_name' => 'สมชาย ใจดี',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-01 10:00 เวลาไทย — รอบ 10 ต.ค. จองได้แค่ไม่กี่ที่
        Carbon::setTestNow(Carbon::parse('2026-10-01 03:00:00', 'UTC'));
        Mail::fake();
        config()->set('services.thaibulksms.enabled', false);

        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'phu-kradueng', 'type' => 'trekking',
            'location' => 'Loei', 'difficulty' => 'easy', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3500, 'status' => 'active',
        ]);
        $this->thin = $this->schedule('2026-10-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(string $date, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $this->trip->id,
            'departure_date' => $date,
            'return_date' => Carbon::parse($date)->addDays(2)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function booking(TripSchedule $schedule, array $overrides = [], int $passengers = 2): Booking
    {
        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['phone' => '0812345678'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 7000,
            'paid_amount' => 7000,
            'payment_type' => 'full',
        ], $overrides));

        for ($i = 0; $i < $passengers; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'นาย',
                'name' => 'สมชาย ใจดี'.$i,
                'phone' => '0812345678',
            ]);
        }

        $schedule->syncBookedSeats();

        return $booking->fresh();
    }

    private function cancelUnderfilled(?TripSchedule $schedule = null, array $body = [])
    {
        $schedule ??= $this->thin;

        return $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/force-majeure", array_merge(['kind' => 'underfilled'], $body));
    }

    private function requestRefund(Booking $booking, ?array $account = self::ACCOUNT)
    {
        return $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/postponement/refund", $account ?? []);
    }

    private function payload(Booking $booking): array
    {
        $booking = $booking->fresh(['schedule.trip', 'passengers', 'seats']);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $booking->user);

        return (new BookingResource($booking))->toArray($request);
    }

    // ── ยกเลิกรอบ ─────────────────────────────────────────────────────────

    public function test_admin_cancels_an_underfilled_round_and_customers_get_both_choices(): void
    {
        $booking = $this->booking($this->thin);

        $this->cancelUnderfilled()
            ->assertOk()
            ->assertJsonPath('data.kind', 'underfilled')
            ->assertJsonPath('data.reason', ForceMajeureService::UNDERFILLED_REASON)
            ->assertJsonPath('data.decide_by', '2026-10-15')
            ->assertJsonPath('data.until', '2027-04-10')
            ->assertJsonPath('data.counts.awaiting', 1);

        $thin = $this->thin->fresh();
        $this->assertSame('cancelled', $thin->status);
        $this->assertSame('underfilled', $thin->postpone_kind);

        $booking->refresh();
        $this->assertTrue($booking->awaitsNewRound());
        $this->assertTrue($booking->isUnderfilledPostponement());
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('2026-10-15', $booking->postpone_decide_by->toDateString());

        $fm = $this->payload($booking)['force_majeure'];
        $this->assertSame('underfilled', $fm['kind']);
        $this->assertSame('awaiting', $fm['state']);
        $this->assertTrue($fm['can_choose']);
        $this->assertTrue($fm['can_request_refund']);
        $this->assertSame(7000.0, $fm['refund_amount']);
        $this->assertSame('2026-10-15', $fm['decide_by']);
        $this->assertSame('15 ตุลาคม 2569', $fm['decide_by_label']);
        $this->assertSame(14, $fm['days_left']);
        $this->assertContains('พร้อมเพย์', $fm['refund_banks']);
        $this->assertSame('force_majeure', $this->payload($booking)['reschedule_mode']);

        $push = SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_postponed')->first();
        $this->assertStringContainsString('รับเงินคืน', $push->title);
        $this->assertStringNotContainsString('เหตุสุดวิสัย', $push->body);

        $sms = SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_postponed')->first();
        $this->assertStringContainsString('รับเงินคืนเต็มจำนวน', $sms->message);
        $this->assertStringContainsString('/reschedule/', $sms->message);

        Mail::assertQueued(TripPostponedMail::class, function (TripPostponedMail $mail) {
            $html = $mail->render();

            return str_contains($mail->envelope()->subject, 'รับเงินคืนเต็มจำนวน')
                && str_contains($html, 'รอบใหม่ฟรี หรือเงินคืนเต็มจำนวน')
                && str_contains($html, '15 ตุลาคม 2569')
                && ! str_contains($html, 'เหตุสุดวิสัย');
        });
    }

    public function test_round_that_reached_the_minimum_cannot_be_cancelled_as_underfilled(): void
    {
        $this->booking($this->thin, passengers: 8);

        $this->cancelUnderfilled()->assertStatus(422);
        $this->assertSame('open', $this->thin->fresh()->status);
    }

    public function test_past_round_cannot_be_cancelled_as_underfilled(): void
    {
        $past = $this->schedule('2026-09-20');
        $this->booking($past);

        $this->cancelUnderfilled($past)->assertStatus(422);
    }

    public function test_force_majeure_still_needs_a_reason_but_underfilled_does_not(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->thin->id}/force-majeure", ['reason' => ''])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->thin->id}/force-majeure", ['kind' => 'bogus', 'reason' => 'x'])
            ->assertStatus(422);

        $this->cancelUnderfilled(body: ['reason' => 'มีผู้จองเพียง 3 ท่าน'])
            ->assertOk()
            ->assertJsonPath('data.reason', 'มีผู้จองเพียง 3 ท่าน');
    }

    public function test_round_chat_explains_both_choices(): void
    {
        $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        $message = ChatMessage::where('schedule_id', $this->thin->id)
            ->where('system_key', 'like', 'force_majeure:%')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('รับเงินคืนเต็มจำนวน', $message->body);
        $this->assertStringContainsString('15 ตุลาคม 2569', $message->body);
        $this->assertStringNotContainsString('⛈️', $message->body);
    }

    // ── เลือกรอบใหม่ ──────────────────────────────────────────────────────

    public function test_customer_chooses_a_new_round_at_the_same_price(): void
    {
        $booking = $this->booking($this->thin);
        $round = $this->schedule('2026-11-07');
        $this->cancelUnderfilled()->assertOk();

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $round->id])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($round->id, $booking->schedule_id);
        $this->assertNotNull($booking->force_majeure_resolved_at);
        $this->assertSame('7000.00', (string) $booking->total_amount);
        $this->assertNull($booking->rescheduled_at);

        $fm = $this->payload($booking)['force_majeure'];
        $this->assertSame('moved', $fm['state']);
        $this->assertFalse($fm['can_request_refund']);

        // ย้ายไปแล้ว — ขอคืนเงินเต็มจำนวนแบบนี้ไม่ได้อีก และยกเลิกทีหลังใช้นโยบายปกติ
        $this->requestRefund($booking)->assertStatus(422);
        $this->assertFalse($booking->fresh()->owesUnderfilledFullRefund());
        $this->assertNotSame(100, app(BookingService::class)->calculateRefundAmount($booking->fresh(['schedule.trip']))['refund_percent']);
    }

    public function test_choosing_is_closed_after_the_decide_by_date_even_inside_the_round_window(): void
    {
        $booking = $this->booking($this->thin);
        $round = $this->schedule('2026-11-07');
        $this->cancelUnderfilled()->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC')); // เลย 15 ต.ค.

        $this->assertFalse($booking->fresh()->canChooseForceMajeureRound());
        $this->assertFalse($this->payload($booking)['can_reschedule']);
        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $round->id])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'เลยกำหนดเลือกรอบเดินทางใหม่แล้ว (ภายใน 15 ตุลาคม 2569) กรุณาติดต่อทีมงาน']);

        // ยังขอคืนเงินเองได้จนกว่างานรายวันจะคืนให้
        $this->assertTrue($this->payload($booking)['force_majeure']['can_request_refund']);
    }

    // ── ขอคืนเงิน ─────────────────────────────────────────────────────────

    public function test_customer_requests_a_full_refund_with_their_bank_account(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        $this->requestRefund($booking)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.refund_status', 'requested')
            ->assertJsonPath('data.force_majeure.state', 'refund_requested')
            ->assertJsonPath('data.force_majeure.refund_account_label', 'กสิกรไทย ••••7890');

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('requested', $booking->refund_status);
        $this->assertSame('7000.00', (string) $booking->refund_amount);
        $this->assertNotNull($booking->force_majeure_resolved_at);
        $this->assertSame(['bank' => 'กสิกรไทย', 'number' => '1234567890', 'name' => 'สมชาย ใจดี'], $booking->refund_account);
        // เลขบัญชีเก็บแบบเข้ารหัส ไม่ใช่ตัวอักษรตรง ๆ
        $this->assertStringNotContainsString('1234567890', (string) DB::table('bookings')->where('id', $booking->id)->value('refund_account'));
        $this->assertArrayNotHasKey('refund_account', $booking->toArray());
        $this->assertSame(0, (int) $this->thin->fresh()->booked_seats);

        $note = SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_refund_requested')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('฿7,000', $note->body);
        $this->assertFalse(SmartNotification::where('user_id', $booking->user_id)->where('type', 'booking_cancelled')->exists());

        $sms = SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_refund_requested')->first();
        $this->assertStringContainsString('7,000', $sms->message);

        // อีเมลยกเลิกต้องไม่พ่วงนโยบาย "มัดจำไม่คืน" ที่ขัดกับการคืนเต็มจำนวน
        Mail::assertQueued(BookingCancelledMail::class, function (BookingCancelledMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'ยอดคืนเงิน (เต็มจำนวน)')
                && str_contains($html, 'กสิกรไทย ••••7890')
                && ! str_contains($html, 'มัดจำ: ไม่คืนทุกกรณี');
        });

        // กดซ้ำไม่ยกเลิก/แจ้งซ้ำ
        $this->requestRefund($booking)->assertStatus(422);
        $this->assertSame(1, SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_refund_requested')->count());
    }

    public function test_deposit_bookings_get_their_deposit_back_too(): void
    {
        $booking = $this->booking($this->thin, [
            'payment_type' => 'deposit', 'paid_amount' => 2000, 'deposit_amount' => 2000,
            'balance_amount' => 5000,
        ]);
        $this->cancelUnderfilled()->assertOk();

        $preview = app(BookingService::class)->calculateRefundAmount($booking->fresh(['schedule.trip']));
        $this->assertSame(100, $preview['refund_percent']);
        $this->assertSame(2000.0, $preview['refund_amount']);

        $this->requestRefund($booking)->assertOk();
        $this->assertSame('2000.00', (string) $booking->fresh()->refund_amount);
    }

    public function test_refund_needs_a_valid_account_when_money_was_paid(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        $this->requestRefund($booking, null)->assertStatus(422)
            ->assertJsonFragment(['message' => 'กรุณากรอกบัญชีสำหรับรับเงินคืน']);
        $this->requestRefund($booking, ['bank' => 'ธนาคารปลอม'] + self::ACCOUNT)->assertStatus(422);
        $this->requestRefund($booking, ['account_number' => '12'] + self::ACCOUNT)->assertStatus(422);
        $this->requestRefund($booking, ['bank' => 'พร้อมเพย์', 'account_number' => '12345678901'] + self::ACCOUNT)
            ->assertStatus(422);
        $this->requestRefund($booking, ['account_name' => ' '] + self::ACCOUNT)->assertStatus(422);

        $this->assertSame('confirmed', $booking->fresh()->status);

        $this->requestRefund($booking, ['bank' => 'พร้อมเพย์', 'account_number' => '081-234-5678'] + self::ACCOUNT)
            ->assertOk();
    }

    public function test_unpaid_booking_is_just_cancelled(): void
    {
        $booking = $this->booking($this->thin, ['status' => 'pending', 'paid_amount' => 0]);
        $this->cancelUnderfilled()->assertOk();

        $this->assertSame(0.0, $this->payload($booking)['force_majeure']['refund_amount']);

        $this->requestRefund($booking, null)->assertOk();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNull($booking->refund_status);
        $this->assertNull($booking->refund_account);
        $this->assertSame('cancelled', ForceMajeureService::stateOf($booking));
    }

    public function test_force_majeure_bookings_cannot_use_the_refund_option(): void
    {
        $booking = $this->booking($this->thin);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->thin->id}/force-majeure", ['reason' => 'น้ำป่าไหลหลาก'])
            ->assertOk();

        $fm = $this->payload($booking)['force_majeure'];
        $this->assertSame('force_majeure', $fm['kind']);
        $this->assertFalse($fm['can_request_refund']);
        $this->assertNull($fm['refund_amount']);
        $this->assertSame([], $fm['refund_banks']);

        $this->requestRefund($booking)->assertStatus(422);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_someone_else_cannot_request_a_refund(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/postponement/refund", [
                'bank' => 'กสิกรไทย', 'account_number' => '1234567890', 'account_name' => 'คนอื่น',
            ])
            ->assertNotFound();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    // ── เลยกำหนด / เตือน ─────────────────────────────────────────────────

    public function test_bookings_left_undecided_are_refunded_automatically(): void
    {
        $silent = $this->booking($this->thin);
        $unpaid = $this->booking($this->thin, ['status' => 'pending', 'paid_amount' => 0]);
        $this->cancelUnderfilled()->assertOk();

        // วันสุดท้าย — ยังไม่คืน
        Carbon::setTestNow(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        (new SendForceMajeureRemindersJob)->handle(app(ForceMajeureService::class));
        $this->assertSame('confirmed', $silent->fresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));
        (new SendForceMajeureRemindersJob)->handle(app(ForceMajeureService::class));

        $silent->refresh();
        $this->assertSame('cancelled', $silent->status);
        $this->assertSame('requested', $silent->refund_status);
        $this->assertSame('7000.00', (string) $silent->refund_amount);
        $this->assertNull($silent->refund_account);
        $this->assertStringContainsString('ไม่ได้เลือกรอบใหม่ภายในกำหนด', $silent->cancellation_reason);

        $note = SmartNotification::where('user_id', $silent->user_id)->where('type', 'trip_refund_requested')->first();
        $this->assertStringContainsString('คืนเงินให้แล้ว', $note->title);
        $this->assertStringContainsString('ติดต่อขอเลขบัญชี', $note->body);

        $this->assertSame('cancelled', $unpaid->fresh()->status);
        $this->assertNull($unpaid->fresh()->refund_status);

        // รันซ้ำไม่ทำอะไรเพิ่ม
        $this->assertSame(0, app(ForceMajeureService::class)->refundExpiredUnderfilled());
    }

    public function test_reminders_at_three_and_one_day_left_with_an_sms_at_three(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();
        $service = app(ForceMajeureService::class);

        Carbon::setTestNow(Carbon::parse('2026-10-12 03:00:00', 'UTC')); // เหลือ 3 วัน
        $service->sendDeadlineReminders();
        $service->sendDeadlineReminders();

        $notes = SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_postponed_reminder')->get();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('รับเงินคืน', $notes->first()->title);
        $sms = SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_postponed_reminder')->first();
        $this->assertStringContainsString('รับเงินคืน', $sms->message);

        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC')); // เหลือ 1 วัน
        $service->sendDeadlineReminders();
        $this->assertSame(2, SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_postponed_reminder')->count());
        $this->assertSame(1, SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_postponed_reminder')->count());
    }

    public function test_force_majeure_reminder_schedule_ignores_underfilled_bookings(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        // 7 วันก่อน force_majeure_until (10 เม.ย. 2570) — จุดเตือนของเหตุสุดวิสัย
        $booking->forceFill(['postpone_decide_by' => '2027-05-01'])->save();
        Carbon::setTestNow(Carbon::parse('2027-04-03 03:00:00', 'UTC'));
        app(ForceMajeureService::class)->sendDeadlineReminders();

        $this->assertFalse(SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_postponed_reminder')->exists());
    }

    public function test_new_round_is_not_offered_to_people_past_their_decide_by_date(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-16 03:00:00', 'UTC'));
        $this->schedule('2026-11-07');

        $this->assertFalse(SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_postponed_new_round')->exists());
    }

    public function test_new_round_is_announced_to_people_still_deciding(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        $this->schedule('2026-11-07');

        $this->assertTrue(SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_postponed_new_round')->exists());
    }

    // ── ทีมงาน ─────────────────────────────────────────────────────────

    public function test_revert_is_refused_once_someone_asked_for_their_money(): void
    {
        $refunded = $this->booking($this->thin);
        $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();
        $this->requestRefund($refunded)->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->thin->id}/force-majeure/revert")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'มีลูกค้า 1 รายการขอคืนเงินไปแล้ว ย้อนการเลื่อนไม่ได้ — ติดต่อลูกค้าที่เหลือเป็นรายคนแทน']);
    }

    public function test_revert_clears_the_underfilled_state(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->thin->id}/force-majeure/revert")
            ->assertOk();

        $this->assertNull($this->thin->fresh()->postpone_kind);
        $booking->refresh();
        $this->assertNull($booking->postpone_kind);
        $this->assertNull($booking->postpone_decide_by);
        $this->assertNull($booking->postponeKind());
    }

    public function test_admin_overview_shows_refund_requests_with_the_full_account(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();
        $this->requestRefund($booking)->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/schedules/{$this->thin->id}/force-majeure")
            ->assertOk()
            ->assertJsonPath('data.counts.refund_requested', 1)
            ->assertJsonPath('data.bookings.0.state', 'refund_requested')
            ->assertJsonPath('data.bookings.0.refund_amount', 7000)
            ->assertJsonPath('data.bookings.0.refund_account.number', '1234567890')
            ->assertJsonPath('data.bookings.0.choose_url', null);
    }

    public function test_refund_request_lands_in_the_action_queue_and_the_admin_refund_closes_it(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();
        $this->requestRefund($booking)->assertOk();

        $group = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/action-queue')->json('data.groups'))
            ->firstWhere('key', 'refund_requests');
        $this->assertSame(1, $group['count']);
        $this->assertSame('/admin/bookings?refund_status=requested', $group['route']);
        $this->assertStringContainsString('กสิกรไทย ••••7890', $group['items'][0]['detail']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/bookings?refund_status=requested')
            ->assertOk()
            ->assertJsonPath('data.0.booking_ref', $booking->booking_ref);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.refund_percent', 100)
            ->assertJsonPath('data.refund_amount', 7000)
            ->assertJsonPath('data.refund_status', 'requested')
            ->assertJsonPath('data.refund_account.name', 'สมชาย ใจดี');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/refund", ['refund_amount' => 7000])
            ->assertOk();

        $this->assertSame('refunded', $booking->fresh()->refund_status);
        $this->assertSame('refunded', $this->payload($booking)['force_majeure']['state']);
        $group = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/action-queue')->json('data.groups'))
            ->firstWhere('key', 'refund_requests');
        $this->assertSame(0, $group['count']);
    }

    public function test_marking_refunded_through_the_status_endpoint_also_closes_the_request(): void
    {
        $booking = $this->booking($this->thin);
        $this->cancelUnderfilled()->assertOk();
        $this->requestRefund($booking)->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/bookings/{$booking->booking_ref}/status", ['status' => 'refunded'])
            ->assertOk();

        $booking->refresh();
        $this->assertSame('refunded', $booking->status);
        $this->assertSame('refunded', $booking->refund_status);
        $this->assertSame(0, Booking::where('refund_status', ForceMajeureService::REFUND_REQUESTED)->count());
    }

    // ── ลิงก์ไม่ต้องล็อกอิน ────────────────────────────────────────────────

    public function test_no_login_page_offers_a_new_round_or_a_refund(): void
    {
        $booking = $this->booking($this->thin);
        $round = $this->schedule('2026-11-07');
        $this->cancelUnderfilled()->assertOk();
        $token = $booking->fresh()->ensureRescheduleToken();

        $this->get("/reschedule/{$token}")
            ->assertOk()
            ->assertSee('เลือกรอบใหม่ หรือรับเงินคืน')
            ->assertSee('ผู้ร่วมเดินทางไม่ครบตามจำนวนขั้นต่ำ')
            ->assertSee('15 ตุลาคม 2569')
            ->assertSee('value="'.$round->id.'"', false)
            ->assertSee('ขอรับเงินคืนเต็มจำนวน')
            ->assertSee('฿7,000')
            ->assertDontSee('⛈️');

        $this->post("/reschedule/{$token}/refund", ['bank' => 'กสิกรไทย', 'account_number' => '', 'account_name' => 'x'])
            ->assertRedirect("/reschedule/{$token}")
            ->assertSessionHasErrors('refund');
        $this->assertSame('confirmed', $booking->fresh()->status);

        $this->post("/reschedule/{$token}/refund", self::ACCOUNT)->assertRedirect("/reschedule/{$token}");

        $this->assertSame('requested', $booking->fresh()->refund_status);
        $this->get("/reschedule/{$token}")
            ->assertOk()
            ->assertSee('รับเรื่องคืนเงินแล้ว')
            ->assertSee('กสิกรไทย ••••7890')
            ->assertDontSee('name="target_schedule_id"', false)
            ->assertDontSee('id="refund-form"', false);
    }

    public function test_no_login_page_for_a_force_majeure_round_has_no_refund_form(): void
    {
        $booking = $this->booking($this->thin);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->thin->id}/force-majeure", ['reason' => 'น้ำป่าไหลหลาก'])
            ->assertOk();
        $token = $booking->fresh()->ensureRescheduleToken();

        $this->get("/reschedule/{$token}")
            ->assertOk()
            ->assertSee('⛈️', false)
            ->assertDontSee('id="refund-form"', false);

        $this->post("/reschedule/{$token}/refund", self::ACCOUNT)->assertRedirect("/reschedule/{$token}");
        $this->assertSame('confirmed', $booking->fresh()->status);
    }
}
