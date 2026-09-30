<?php

namespace Tests\Feature;

use App\Http\Resources\BookingResource;
use App\Jobs\SendBalanceDueRemindersJob;
use App\Jobs\SendInstallmentRemindersJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Mail\TripPostponedMail;
use App\Mail\TripResumedMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\ChatMessage;
use App\Models\ForceMajeureSeatHold;
use App\Models\InstallmentPayment;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use App\Services\ForceMajeureService;
use App\Services\MailService;
use App\Services\SmsService;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รอบที่ออกไม่ได้เพราะเหตุสุดวิสัย → ลูกค้าเลือกรอบใหม่ของทริปเดิมได้เอง (เงื่อนไขข้อ 6)
 */
class ForceMajeurePostponementTest extends TestCase
{
    use RefreshDatabase;

    private Trip $trip;

    private TripSchedule $flooded;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-01 10:00 เวลาไทย — รอบที่โดนน้ำป่าผ่านไปแล้วเมื่อ 27 ก.ย.
        Carbon::setTestNow(Carbon::parse('2026-10-01 03:00:00', 'UTC'));
        Mail::fake();
        config()->set('services.thaibulksms.enabled', false);

        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->trip = Trip::create([
            'title' => 'น้ำตกทีลอซู', 'slug' => 'thi-lo-su', 'type' => 'trekking',
            'location' => 'Tak', 'difficulty' => 'easy', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3500, 'status' => 'active',
        ]);
        $this->flooded = $this->schedule('2026-09-27');
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

    private function postpone(string $reason = 'น้ำป่าไหลหลาก อุทยานประกาศปิด')
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure", ['reason' => $reason]);
    }

    private function payload(Booking $booking): array
    {
        $booking = $booking->fresh(['schedule.trip', 'passengers', 'seats']);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $booking->user);

        return (new BookingResource($booking))->toArray($request);
    }

    public function test_admin_postpones_round_and_every_booking_gets_a_new_round_right(): void
    {
        $a = $this->booking($this->flooded);
        $b = $this->booking($this->flooded, ['status' => 'pending', 'paid_amount' => 0]);
        $gone = $this->booking($this->flooded, ['status' => 'cancelled']);

        $this->postpone()
            ->assertOk()
            ->assertJsonPath('data.counts.total', 2)
            ->assertJsonPath('data.counts.awaiting', 2)
            ->assertJsonPath('data.until', '2027-03-27');

        $flooded = $this->flooded->fresh();
        $this->assertSame('cancelled', $flooded->status);
        $this->assertNotNull($flooded->force_majeure_at);
        $this->assertSame('น้ำป่าไหลหลาก อุทยานประกาศปิด', $flooded->force_majeure_reason);

        foreach ([$a, $b] as $booking) {
            $booking->refresh();
            $this->assertTrue($booking->awaitsNewRound());
            $this->assertSame('2027-03-27', $booking->force_majeure_until->toDateString());
            // ใบจองยังอยู่ เงินยังอยู่ — ไม่ถูกยกเลิก
            $this->assertContains($booking->status, ['confirmed', 'pending']);
        }
        $this->assertNull($gone->fresh()->force_majeure_at);

        $this->assertSame(2, SmartNotification::where('type', 'trip_postponed')->count());
        Mail::assertQueued(TripPostponedMail::class, 2);
        $sms = SmsLog::where('booking_id', $a->id)->where('sms_type', 'trip_postponed')->first();
        $this->assertNotNull($sms);
        // ลิงก์ที่เปิดได้โดยไม่ต้องล็อกอิน (ลูกค้าบัญชีเงา)
        $this->assertNotNull($a->fresh()->reschedule_token);
        $this->assertStringContainsString('/reschedule/'.$a->fresh()->reschedule_token, $sms->message);
    }

    public function test_postponing_twice_is_refused_and_reason_is_required(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure", ['reason' => ''])
            ->assertStatus(422);

        $this->postpone()->assertOk();
        $this->postpone()->assertStatus(422);
    }

    public function test_customers_cannot_postpone_a_round(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure", ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_waitlist_of_the_cancelled_round_is_closed_and_told(): void
    {
        $waiter = User::factory()->create();
        WaitlistEntry::create([
            'user_id' => $waiter->id, 'schedule_id' => $this->flooded->id,
            'seat_count' => 1, 'priority' => 0, 'status' => 'waiting',
        ]);

        $this->postpone()->assertOk();

        $this->assertSame('cancelled', WaitlistEntry::where('user_id', $waiter->id)->value('status'));
        $this->assertTrue(SmartNotification::where('user_id', $waiter->id)->where('type', 'waitlist_round_cancelled')->exists());
    }

    public function test_right_ignores_the_20_day_rule_and_the_used_standard_reschedule(): void
    {
        // เคยใช้สิทธิ์เลื่อนปกติไปแล้ว และวันเดินทางผ่านไปแล้ว — ปกติจะเลื่อนไม่ได้เลย
        $booking = $this->booking($this->flooded, ['rescheduled_at' => now()->subMonth()]);
        $this->assertFalse($this->payload($booking)['can_reschedule']);

        $this->postpone()->assertOk();

        $payload = $this->payload($booking);
        $this->assertTrue($payload['can_reschedule']);
        $this->assertSame('force_majeure', $payload['reschedule_mode']);
        $this->assertSame('2027-03-27', $payload['reschedule_latest_departure']);
        $this->assertTrue($payload['force_majeure']['awaiting']);
        $this->assertTrue($payload['force_majeure']['can_choose']);
        $this->assertSame(177, $payload['force_majeure']['days_left']);
        $this->assertFalse($payload['can_review']);
    }

    public function test_customer_chooses_a_new_round_at_the_same_price(): void
    {
        $booking = $this->booking($this->flooded);
        $next = $this->schedule('2026-11-08');
        $this->postpone()->assertOk();

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $next->id])
            ->assertOk()
            ->assertJsonPath('data.reschedule_mode', 'standard')
            ->assertJsonPath('data.force_majeure.awaiting', false);

        $booking->refresh();
        $this->assertSame($next->id, $booking->schedule_id);
        $this->assertSame('7000.00', $booking->total_amount);
        $this->assertNotNull($booking->force_majeure_resolved_at);
        // สิทธิ์เลื่อนปกติยังอยู่ครบ
        $this->assertNull($booking->rescheduled_at);
        $this->assertSame(2, $next->fresh()->booked_seats);
        $this->assertTrue(SmartNotification::where('type', 'booking_rescheduled')
            ->where('title', 'ได้รอบเดินทางใหม่แล้ว')->exists());

        // ใช้สิทธิ์ไปแล้ว — ย้ายซ้ำด้วยสิทธิ์นี้ไม่ได้ (เหลือแต่สิทธิ์ปกติ ซึ่งติดกติกา 20 วัน)
        $later = $this->schedule('2026-12-20');
        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $later->id])
            ->assertOk();
        $this->assertNotNull($booking->fresh()->rescheduled_at);
    }

    public function test_seats_are_assigned_when_the_customer_does_not_pick_any(): void
    {
        // LIFF ไม่มีผังให้เลือก — เดิมรอบที่มีผังที่นั่งจะเลื่อนผ่าน LINE ไม่ได้เลย
        $booking = $this->booking($this->flooded);
        foreach (['A1', 'A2'] as $i => $seat) {
            BookingSeat::create(['booking_id' => $booking->id, 'schedule_id' => $this->flooded->id, 'seat_id' => $seat, 'passenger_name' => 'x'.$i]);
        }
        $next = $this->schedule('2026-11-08');
        $someone = $this->booking($next, passengers: 1);
        $firstSeat = collect($next->resolveSeatLayout()['seats'])->pluck('id')->first();
        BookingSeat::create(['booking_id' => $someone->id, 'schedule_id' => $next->id, 'seat_id' => $firstSeat, 'passenger_name' => 'y']);
        $this->postpone()->assertOk();

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $next->id])
            ->assertOk();

        $seats = BookingSeat::where('booking_id', $booking->id)->get();
        $this->assertCount(2, $seats);
        $this->assertTrue($seats->every(fn ($s) => $s->schedule_id === $next->id));
        $this->assertNotContains($firstSeat, $seats->pluck('seat_id')->all());
        $this->assertCount(2, $seats->pluck('seat_id')->unique());
    }

    public function test_pickup_point_follows_to_the_same_named_point_of_the_new_round(): void
    {
        $oldPoint = SchedulePickupPoint::create(['schedule_id' => $this->flooded->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพ', 'pickup_location' => 'BTS หมอชิต', 'price' => 3500]);
        $booking = $this->booking($this->flooded, ['pickup_point_id' => $oldPoint->id, 'pickup_region' => 'bkk']);
        $next = $this->schedule('2026-11-08');
        SchedulePickupPoint::create(['schedule_id' => $next->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพ', 'pickup_location' => 'รังสิต', 'price' => 3500]);
        $newPoint = SchedulePickupPoint::create(['schedule_id' => $next->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพ', 'pickup_location' => 'BTS หมอชิต', 'price' => 3500]);
        $this->postpone()->assertOk();

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $next->id])
            ->assertOk();

        $this->assertSame($newPoint->id, $booking->fresh()->pickup_point_id);
    }

    public function test_new_round_must_depart_inside_the_window(): void
    {
        $booking = $this->booking($this->flooded);
        $tooLate = $this->schedule('2027-03-28');
        $lastDay = $this->schedule('2027-03-27');
        $this->postpone()->assertOk();

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $tooLate->id])
            ->assertStatus(422);

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $lastDay->id])
            ->assertOk();
    }

    public function test_cannot_choose_another_trip_or_a_closed_round(): void
    {
        $booking = $this->booking($this->flooded);
        $otherTrip = Trip::create([
            'title' => 'Other', 'slug' => 'other', 'type' => 'trekking', 'location' => 'X',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 10,
            'price_per_person' => 900, 'status' => 'active',
        ]);
        $foreign = TripSchedule::create([
            'trip_id' => $otherTrip->id, 'departure_date' => '2026-11-01', 'return_date' => '2026-11-01',
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $closed = $this->schedule('2026-11-15', ['status' => 'closed']);
        $this->postpone()->assertOk();

        foreach ([$foreign, $closed, $this->flooded] as $target) {
            $this->actingAs($booking->user)
                ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $target->id])
                ->assertStatus(422);
        }
        $this->assertTrue($booking->fresh()->awaitsNewRound());
    }

    public function test_round_without_enough_seats_is_refused(): void
    {
        $booking = $this->booking($this->flooded, passengers: 3);
        $small = $this->schedule('2026-11-08', ['total_seats' => 2]);
        $this->postpone()->assertOk();

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $small->id])
            ->assertStatus(422);
    }

    public function test_expired_right_can_no_longer_be_used_by_the_customer(): void
    {
        $booking = $this->booking($this->flooded);
        $next = $this->schedule('2027-03-20');
        $this->postpone()->assertOk();

        Carbon::setTestNow(Carbon::parse('2027-03-28 03:00:00', 'UTC'));

        $payload = $this->payload($booking);
        $this->assertFalse($payload['can_reschedule']);
        $this->assertTrue($payload['force_majeure']['expired']);

        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $next->id])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure")
            ->assertOk()
            ->assertJsonPath('data.counts.expired', 1);
    }

    public function test_deposit_balance_and_installments_follow_the_new_round(): void
    {
        $deposit = $this->booking($this->flooded, [
            'payment_type' => 'deposit', 'paid_amount' => 2000,
            'deposit_amount' => 2000, 'balance_amount' => 5000,
            'balance_due_at' => '2026-09-12',
        ]);
        $installment = $this->booking($this->flooded, [
            'payment_type' => 'installment', 'paid_amount' => 3500, 'installment_count' => 2,
        ]);
        InstallmentPayment::create(['booking_id' => $installment->id, 'installment_no' => 1, 'amount' => 3500, 'due_date' => '2026-08-01', 'status' => 'paid']);
        InstallmentPayment::create(['booking_id' => $installment->id, 'installment_no' => 2, 'amount' => 3500, 'due_date' => '2026-09-13', 'status' => 'overdue']);

        $next = $this->schedule('2026-12-06'); // เลื่อนไป 70 วัน
        $this->postpone()->assertOk();

        foreach ([$deposit, $installment] as $booking) {
            $this->actingAs($booking->user)
                ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $next->id])
                ->assertOk();
        }

        // ยอดคงเหลือ: 15 วันก่อนรอบใหม่
        $this->assertSame('2026-11-21', $deposit->fresh()->balance_due_at->toDateString());

        $rows = InstallmentPayment::where('booking_id', $installment->id)->orderBy('installment_no')->get();
        $this->assertSame('2026-08-01', $rows[0]->due_date->toDateString()); // จ่ายแล้วไม่ขยับ
        $this->assertSame('paid', $rows[0]->status);
        $this->assertSame('2026-11-22', $rows[1]->due_date->toDateString()); // +70 วัน
        $this->assertSame('pending', $rows[1]->status);
    }

    public function test_payment_reminders_pause_while_the_customer_has_not_chosen(): void
    {
        $deposit = $this->booking($this->flooded, [
            'payment_type' => 'deposit', 'paid_amount' => 2000,
            'deposit_amount' => 2000, 'balance_amount' => 5000,
            'balance_due_at' => now()->addDays(2)->toDateString(),
        ]);
        $installment = $this->booking($this->flooded, ['payment_type' => 'installment', 'installment_count' => 2]);
        InstallmentPayment::create(['booking_id' => $installment->id, 'installment_no' => 2, 'amount' => 3500, 'due_date' => '2026-09-20', 'status' => 'pending']);
        $this->postpone()->assertOk();

        (new SendBalanceDueRemindersJob)->handle(app(MailService::class), app(SmsService::class));
        (new SendInstallmentRemindersJob)->handle(app(SmsService::class), app(MailService::class));

        $this->assertFalse(SmsLog::where('booking_id', $deposit->id)->where('sms_type', 'balance_due_reminder')->exists());
        $this->assertSame('pending', InstallmentPayment::where('booking_id', $installment->id)->value('status'));
    }

    public function test_new_round_announcement_reaches_people_still_choosing(): void
    {
        $waiting = $this->booking($this->flooded);
        $this->postpone()->assertOk();

        $round = $this->schedule('2026-11-29');
        $this->schedule('2027-05-01'); // นอกกรอบเวลา — ไม่ต้องชวน

        $notes = SmartNotification::where('user_id', $waiting->user_id)->where('type', 'trip_postponed_new_round')->get();
        $this->assertCount(1, $notes);
        $this->assertSame($round->id, $notes->first()->data['schedule_id']);

        // รอบเดียวกันเปิดซ้ำ (ปิดแล้วเปิดใหม่) ไม่ส่งซ้ำ
        $round->update(['status' => 'closed']);
        $round->update(['status' => 'open']);
        $this->assertSame(1, SmartNotification::where('user_id', $waiting->user_id)->where('type', 'trip_postponed_new_round')->count());
    }

    public function test_deadline_reminders_at_seven_days_include_an_sms(): void
    {
        $booking = $this->booking($this->flooded);
        $this->postpone()->assertOk();

        Carbon::setTestNow(Carbon::parse('2027-03-20 03:00:00', 'UTC')); // เหลือ 7 วัน
        app(ForceMajeureService::class)->sendDeadlineReminders();
        app(ForceMajeureService::class)->sendDeadlineReminders();

        $this->assertSame(1, SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_postponed_reminder')->count());
        $this->assertTrue(SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_postponed_reminder')->exists());
    }

    public function test_admin_move_closes_the_right(): void
    {
        $booking = $this->booking($this->flooded);
        $next = $this->schedule('2026-11-08');
        $this->postpone()->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $this->flooded->id,
                'target_schedule_id' => $next->id,
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($next->id, $booking->schedule_id);
        $this->assertFalse($booking->awaitsNewRound());
        $this->assertNotNull($booking->force_majeure_resolved_at);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure")
            ->assertJsonPath('data.counts.moved', 1)
            ->assertJsonPath('data.bookings.0.moved_to.schedule_id', $next->id);
    }

    public function test_postponed_round_cannot_be_reopened(): void
    {
        $this->postpone()->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/schedules/{$this->flooded->id}", ['status' => 'open'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/schedules/bulk-update', ['ids' => [$this->flooded->id], 'data' => ['status' => 'open']])
            ->assertStatus(422);

        $this->assertSame('cancelled', $this->flooded->fresh()->status);
    }

    public function test_new_round_still_gets_its_trip_reminder_after_the_old_one_was_sent(): void
    {
        $booking = $this->booking($this->flooded);
        // เตือน 7 วันของรอบเดิมถูกส่งไปแล้ว
        $old = SmartNotification::create([
            'user_id' => $booking->user_id, 'type' => 'trip_reminder', 'title' => 'x', 'body' => 'x',
            'data' => ['booking_ref' => $booking->booking_ref, 'days_before' => 7],
        ]);
        SmartNotification::whereKey($old->id)->update(['created_at' => now()->subDays(11)]);
        $next = $this->schedule(now('Asia/Bangkok')->addDays(7)->toDateString());
        $this->postpone()->assertOk();
        $this->actingAs($booking->user)
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", ['target_schedule_id' => $next->id])
            ->assertOk();

        (new SendTripReminderNotificationsJob)->handle();

        $this->assertSame(2, SmartNotification::where('user_id', $booking->user_id)
            ->where('type', 'trip_reminder')->where('data->days_before', 7)->count());
    }

    // ── ย้อนการเลื่อน (กดผิดรอบ) ─────────────────────────────────────────

    private function revert()
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure/revert");
    }

    public function test_admin_can_revert_a_mistaken_postponement(): void
    {
        $upcoming = $this->schedule('2026-10-20');
        $this->flooded = $upcoming;
        $booking = $this->booking($upcoming);
        $waiter = User::factory()->create();
        WaitlistEntry::create([
            'user_id' => $waiter->id, 'schedule_id' => $upcoming->id,
            'seat_count' => 1, 'priority' => 0, 'status' => 'waiting',
        ]);
        $leftBefore = User::factory()->create();
        WaitlistEntry::create([
            'user_id' => $leftBefore->id, 'schedule_id' => $upcoming->id,
            'seat_count' => 1, 'priority' => 0, 'status' => 'cancelled',
        ]);
        $this->postpone()->assertOk()->assertJsonPath('data.can_revert', true);

        $this->revert()->assertOk()->assertJsonPath('data.force_majeure_at', null);

        $upcoming->refresh();
        $this->assertSame('open', $upcoming->status);
        $this->assertNull($upcoming->force_majeure_at);
        $this->assertNull($upcoming->force_majeure_prev_status);

        $booking->refresh();
        $this->assertNull($booking->force_majeure_at);
        $this->assertNull($booking->force_majeure_until);
        $this->assertFalse($booking->awaitsNewRound());
        $this->assertSame('standard', $this->payload($booking)['reschedule_mode']);
        $this->assertNull($this->payload($booking)['force_majeure']);

        // คิวที่ถูกปิดเพราะการเลื่อนได้คืน ส่วนคนที่ออกเองก่อนหน้าไม่ได้คืน
        // (รอบมีที่ว่าง คิวจึงได้รับสิทธิ์ต่อทันที)
        $this->assertContains(WaitlistEntry::where('user_id', $waiter->id)->value('status'), ['waiting', 'offered']);
        $this->assertSame('cancelled', WaitlistEntry::where('user_id', $leftBefore->id)->value('status'));
        $this->assertTrue(SmartNotification::where('user_id', $waiter->id)->where('type', 'waitlist_round_reopened')->exists());

        // แจ้งลูกค้าทุกช่องทางว่าเดินทางตามเดิม
        $this->assertTrue(SmartNotification::where('user_id', $booking->user_id)->where('type', 'trip_resumed')->exists());
        Mail::assertQueued(TripResumedMail::class, 1);
        $this->assertTrue(SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_resumed')->exists());
        $this->assertTrue(ChatMessage::where('schedule_id', $upcoming->id)->where('system_key', 'like', 'force_majeure_resumed:%')->exists());

        // กลับมาเปิดไม่ใช่รอบใหม่ — ห้ามประกาศ "เปิดรอบใหม่" หาทุกคน
        $this->assertFalse(SmartNotification::where('type', 'new_schedule')->exists());
    }

    public function test_revert_is_refused_once_someone_has_moved(): void
    {
        $a = $this->booking($this->flooded);
        $this->booking($this->flooded);
        $next = $this->schedule('2026-11-08');
        $this->postpone()->assertOk();

        $this->actingAs($a->user)
            ->postJson("/api/v1/bookings/{$a->booking_ref}/reschedule", ['target_schedule_id' => $next->id])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure")
            ->assertJsonPath('data.can_revert', false);
        $this->revert()->assertStatus(422);
        $this->assertSame('cancelled', $this->flooded->fresh()->status);
    }

    public function test_revert_is_refused_for_a_round_that_was_already_cancelled(): void
    {
        $this->flooded->update(['status' => 'cancelled']);
        $this->booking($this->flooded);
        $this->postpone()->assertOk();

        $this->revert()->assertStatus(422);
        $this->assertNotNull($this->flooded->fresh()->force_majeure_at);
    }

    public function test_postponing_again_after_a_revert_notifies_again(): void
    {
        $booking = $this->booking($this->flooded);
        $this->postpone()->assertOk();
        Carbon::setTestNow(now()->addMinutes(5));
        $this->revert()->assertOk();
        Carbon::setTestNow(now()->addMinutes(5));
        $this->postpone()->assertOk();

        $this->assertSame(2, SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_postponed')->count());
        $this->assertSame(2, ChatMessage::where('schedule_id', $this->flooded->id)->where('system_key', 'like', 'force_majeure:%')->count());
    }

    // ── ห้องแชท ────────────────────────────────────────────────────────

    public function test_postponement_is_announced_in_the_round_chat(): void
    {
        $this->booking($this->flooded);
        $this->postpone()->assertOk();

        $message = ChatMessage::where('schedule_id', $this->flooded->id)
            ->where('system_key', 'like', 'force_majeure:%')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('น้ำป่าไหลหลาก', $message->body);
        $this->assertStringContainsString('27 มีนาคม 2570', $message->body);
        $this->assertSame('system', $message->sender_role);
    }

    // ── กันที่นั่งในรอบใหม่ ─────────────────────────────────────────────

    public function test_new_round_holds_seats_for_postponed_customers_first(): void
    {
        $first = $this->booking($this->flooded);           // 2 คน
        $second = $this->booking($this->flooded);          // 2 คน — ที่ไม่พอแล้ว
        $this->postpone()->assertOk();

        $round = $this->schedule('2026-11-29', ['total_seats' => 3]);

        $hold = ForceMajeureSeatHold::where('schedule_id', $round->id)->get();
        $this->assertCount(1, $hold);
        $this->assertSame($first->id, $hold->first()->booking_id);
        $this->assertSame(2, $hold->first()->seat_count);
        $this->assertStringContainsString('กันที่นั่งไว้ให้ 2 ที่',
            SmartNotification::where('user_id', $first->user_id)->where('type', 'trip_postponed_new_round')->value('body'));
        $this->assertFalse(SmartNotification::where('user_id', $second->user_id)->where('type', 'trip_postponed_new_round')->exists());

        // คนทั่วไปเห็นเหลือ 1 ที่ เจ้าของสิทธิ์เห็นที่ของตัวเองในใบจอง
        $this->getJson('/api/v1/trips/thi-lo-su/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.bookable_seats', 1)
            ->assertJsonPath('data.0.held_seats', 2);
        $this->assertSame(2, app(WaitlistService::class)->heldSeats($round->id));
        $this->assertSame(0, app(WaitlistService::class)->heldSeats($round->id, exceptUserId: $first->user_id));
        $this->assertSame($round->id, $this->payload($first)['force_majeure']['holds'][0]['schedule_id']);

        // เจ้าของสิทธิ์ย้ายเข้าได้ แล้วการกันถูกปิด
        $this->actingAs($first->user)
            ->postJson("/api/v1/bookings/{$first->booking_ref}/reschedule", ['target_schedule_id' => $round->id])
            ->assertOk();
        $this->assertNotNull(ForceMajeureSeatHold::first()->released_at);
        $this->assertSame(0, app(WaitlistService::class)->heldSeats($round->id));
    }

    public function test_held_seats_cannot_be_taken_by_another_postponed_customer(): void
    {
        $first = $this->booking($this->flooded);
        $this->postpone()->assertOk();
        $round = $this->schedule('2026-11-29', ['total_seats' => 3]);

        // อีกคนจากรอบอื่นที่ถูกเลื่อนทีหลัง อยากได้ 2 ที่ในรอบเดียวกัน
        $otherFlooded = $this->schedule('2026-10-04');
        $late = $this->booking($otherFlooded);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$otherFlooded->id}/force-majeure", ['reason' => 'พายุ'])
            ->assertOk();

        $this->actingAs($late->user)
            ->postJson("/api/v1/bookings/{$late->booking_ref}/reschedule", ['target_schedule_id' => $round->id])
            ->assertStatus(422);
        $this->assertTrue($first->fresh()->awaitsNewRound());
    }

    public function test_holds_expire_after_48_hours(): void
    {
        $first = $this->booking($this->flooded);
        $this->postpone()->assertOk();
        $round = $this->schedule('2026-11-29', ['total_seats' => 3]);
        $this->assertSame(2, app(WaitlistService::class)->heldSeats($round->id));

        Carbon::setTestNow(now()->addHours(48)->addMinute());

        $this->assertSame(0, app(WaitlistService::class)->heldSeats($round->id));
        $this->assertSame(1, app(ForceMajeureService::class)->releaseExpiredHolds());
        $this->assertNotNull(ForceMajeureSeatHold::first()->released_at);
        $this->assertSame([], $this->payload($first)['force_majeure']['holds']);
    }

    public function test_cancelling_or_admin_moving_a_booking_releases_its_holds(): void
    {
        $a = $this->booking($this->flooded);
        $b = $this->booking($this->flooded);
        $this->postpone()->assertOk();
        $round = $this->schedule('2026-11-29');
        $this->assertSame(4, app(WaitlistService::class)->heldSeats($round->id));

        app(BookingService::class)->cancelBooking($a->fresh(), 'คืนเงิน');
        $this->assertSame(2, app(WaitlistService::class)->heldSeats($round->id));

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $this->flooded->id,
                'target_schedule_id' => $this->schedule('2026-12-13')->id,
            ])
            ->assertOk();
        $this->assertFalse($b->fresh()->awaitsNewRound());
        $this->assertSame(0, app(WaitlistService::class)->heldSeats($round->id));
        $this->assertSame(0, ForceMajeureSeatHold::whereNull('released_at')->count());
    }

    public function test_join_trip_bookings_are_told_but_hold_no_van_seats(): void
    {
        $join = $this->booking($this->flooded, ['is_join_trip' => true]);
        $this->postpone()->assertOk();
        $this->schedule('2026-11-29', ['join_trip_enabled' => true, 'join_trip_price' => 1500]);

        $this->assertSame(0, ForceMajeureSeatHold::count());
        $this->assertTrue(SmartNotification::where('user_id', $join->user_id)->where('type', 'trip_postponed_new_round')->exists());
    }

    // ── ลิงก์เลือกรอบแบบไม่ต้องล็อกอิน (/reschedule/{token}) ─────────────────

    private function tokenFor(Booking $booking): string
    {
        return $booking->fresh()->ensureRescheduleToken();
    }

    public function test_shadow_customer_chooses_a_round_without_logging_in(): void
    {
        $shadow = User::factory()->create(['is_shadow' => true, 'password' => null]);
        $booking = $this->booking($this->flooded, ['user_id' => $shadow->id]);
        $round = $this->schedule('2026-11-29', ['total_seats' => 5]);
        $this->schedule('2027-05-01'); // นอกกรอบ — ต้องไม่ขึ้น
        $this->postpone()->assertOk();
        $token = $this->tokenFor($booking);

        $this->get("/reschedule/{$token}")
            ->assertOk()
            ->assertSee('เลือกรอบเดินทางใหม่')
            ->assertSee('น้ำป่าไหลหลาก')
            ->assertSee('27 มีนาคม 2570')
            ->assertSee('value="'.$round->id.'"', false)
            ->assertDontSee('1 พฤษภาคม 2570');

        $this->post("/reschedule/{$token}", ['target_schedule_id' => $round->id])
            ->assertRedirect("/reschedule/{$token}");

        $booking->refresh();
        $this->assertSame($round->id, $booking->schedule_id);
        $this->assertNotNull($booking->force_majeure_resolved_at);

        $this->get("/reschedule/{$token}")
            ->assertOk()
            ->assertSee('ได้รอบเดินทางใหม่แล้ว')
            ->assertSee('29 พฤศจิกายน 2569')
            ->assertDontSee('name="target_schedule_id"', false);
    }

    public function test_link_shows_the_held_seats_for_this_customer(): void
    {
        $booking = $this->booking($this->flooded);
        $this->postpone()->assertOk();
        $this->schedule('2026-11-29', ['total_seats' => 2]); // ทั้งรอบกันไว้ให้คนนี้

        $this->get('/reschedule/'.$this->tokenFor($booking))
            ->assertOk()
            ->assertSee('กันที่ไว้ให้คุณ 2 ที่')
            ->assertSee('ว่าง 2 ที่');
    }

    public function test_link_refuses_a_round_outside_the_rules(): void
    {
        $booking = $this->booking($this->flooded);
        $late = $this->schedule('2027-05-01');
        $this->postpone()->assertOk();
        $token = $this->tokenFor($booking);

        $this->post("/reschedule/{$token}", ['target_schedule_id' => $late->id])
            ->assertRedirect("/reschedule/{$token}")
            ->assertSessionHasErrors('target_schedule_id');

        $this->assertTrue($booking->fresh()->awaitsNewRound());
        $this->followingRedirects()
            ->post("/reschedule/{$token}", ['target_schedule_id' => $late->id])
            ->assertSee('เลือกได้เฉพาะรอบที่ออกเดินทางภายใน');
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->get('/reschedule/doesnotexist123')->assertNotFound();
        $this->post('/reschedule/doesnotexist123', ['target_schedule_id' => 1])->assertNotFound();
    }

    public function test_link_after_revert_says_the_trip_goes_ahead(): void
    {
        $upcoming = $this->schedule('2026-10-20');
        $this->flooded = $upcoming;
        $booking = $this->booking($upcoming);
        $this->postpone()->assertOk();
        $token = $this->tokenFor($booking);
        $this->revert()->assertOk();

        $this->get("/reschedule/{$token}")
            ->assertOk()
            ->assertSee('เดินทางตามกำหนด')
            ->assertDontSee('name="target_schedule_id"', false);

        // โพสต์ค้างจากหน้าเก่าต้องไม่ย้ายใบจอง
        $other = $this->schedule('2026-11-08');
        $this->post("/reschedule/{$token}", ['target_schedule_id' => $other->id]);
        $this->assertSame($upcoming->id, $booking->fresh()->schedule_id);
    }

    public function test_link_for_an_expired_or_cancelled_booking_cannot_move_it(): void
    {
        $expired = $this->booking($this->flooded);
        $cancelled = $this->booking($this->flooded);
        $next = $this->schedule('2027-03-20');
        $this->postpone()->assertOk();
        $expiredToken = $this->tokenFor($expired);
        $cancelledToken = $this->tokenFor($cancelled);
        $cancelled->update(['status' => 'cancelled']);

        $this->get("/reschedule/{$cancelledToken}")->assertOk()->assertSee('การจองนี้ถูกยกเลิกแล้ว');
        $this->post("/reschedule/{$cancelledToken}", ['target_schedule_id' => $next->id]);
        $this->assertSame($this->flooded->id, $cancelled->fresh()->schedule_id);

        Carbon::setTestNow(Carbon::parse('2027-03-28 03:00:00', 'UTC'));
        $this->get("/reschedule/{$expiredToken}")->assertOk()->assertSee('เลยกำหนดเลือกรอบใหม่แล้ว');
        $this->post("/reschedule/{$expiredToken}", ['target_schedule_id' => $next->id]);
        $this->assertSame($this->flooded->id, $expired->fresh()->schedule_id);
    }

    public function test_admin_overview_gives_a_copyable_link_for_people_still_choosing(): void
    {
        $booking = $this->booking($this->flooded);
        $this->postpone()->assertOk();

        $url = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure")
            ->assertOk()
            ->json('data.bookings.0.choose_url');

        $this->assertSame(url('/reschedule/'.$booking->fresh()->reschedule_token), $url);
    }

    public function test_email_links_to_the_no_login_page(): void
    {
        $this->booking($this->flooded);
        $this->postpone()->assertOk();

        Mail::assertQueued(TripPostponedMail::class, function (TripPostponedMail $mail) {
            return str_contains($mail->render(), '/reschedule/'.$mail->booking->fresh()->reschedule_token);
        });
    }

    public function test_splitting_a_postponed_booking_does_not_copy_its_link(): void
    {
        $booking = $this->booking($this->flooded, passengers: 2);
        $this->postpone()->assertOk();
        $this->tokenFor($booking);
        $next = $this->schedule('2026-11-08');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $this->flooded->id,
                'target_schedule_id' => $next->id,
                'passenger_ids' => [$booking->passengers()->first()->id],
            ])
            ->assertOk();

        $split = Booking::where('schedule_id', $next->id)->first();
        $this->assertNotNull($split);
        $this->assertNull($split->reschedule_token);
        $this->assertNotNull($booking->fresh()->reschedule_token);
    }
}
