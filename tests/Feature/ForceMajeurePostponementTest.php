<?php

namespace Tests\Feature;

use App\Http\Resources\BookingResource;
use App\Jobs\SendBalanceDueRemindersJob;
use App\Jobs\SendInstallmentRemindersJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Mail\TripPostponedMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\InstallmentPayment;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\ForceMajeureService;
use App\Services\MailService;
use App\Services\SmsService;
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
        $this->assertStringContainsString('my-bookings?reschedule='.$a->booking_ref, $sms->message);
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
}
