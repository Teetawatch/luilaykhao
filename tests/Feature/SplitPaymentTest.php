<?php

namespace Tests\Feature;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.thaibulksms.enabled', false);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Split Trip', 'slug' => 'split-trip', 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(2)->toDateString(),
            'return_date' => now()->addMonths(2)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /** มัดจำ 1000 จาก 4000 — เหลือ balance 3000 กับผู้เดินทาง 3 คน */
    private function makeDepositBooking(User $owner, int $passengerCount = 3): Booking
    {
        $schedule = $this->makeSchedule();

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 4000,
            'paid_amount' => 1000,
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 3000,
            'balance_due_at' => now()->addMonth(),
        ]);

        for ($i = 1; $i <= $passengerCount; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => "Passenger {$i}",
                'phone' => '081000000'.$i,
            ]);
        }

        return $booking;
    }

    private function addActiveMember(Booking $booking, User $user, ?int $passengerId = null): BookingMember
    {
        return BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'passenger_id' => $passengerId,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'invited_by' => $booking->user_id,
            'accepted_at' => now(),
        ]);
    }

    // ── เริ่มแบ่งจ่าย ──────────────────────────────────────────

    public function test_owner_can_setup_equal_split(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.total_shares', 3)
            ->assertJsonPath('data.paid_shares', 0);

        $shares = $booking->splitShares()->get();
        $this->assertCount(3, $shares);
        $this->assertEquals(3000.0, (float) $shares->sum('amount'));
        $this->assertEquals(1000.0, (float) $shares->first()->amount);
        $this->assertNotEmpty($shares->first()->pay_token);
    }

    public function test_equal_split_links_members_and_sends_push(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $passenger = $booking->passengers()->orderBy('id')->get()[1];
        $member = $this->addActiveMember($booking, $friend, $passenger->id);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->where('passenger_id', $passenger->id)->first();
        $this->assertEquals($member->id, $share->member_id);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $friend->id,
            'type' => 'split_share_created',
        ]);
    }

    public function test_owner_can_setup_custom_amounts(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $passengerIds = $booking->passengers()->pluck('id');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split", [
                'shares' => [
                    ['passenger_id' => $passengerIds[0], 'amount' => 500],
                    ['passenger_id' => $passengerIds[1], 'amount' => 1500],
                    ['passenger_id' => $passengerIds[2], 'amount' => 1000],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_shares', 3);
    }

    public function test_custom_amounts_must_sum_to_outstanding_balance(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split", [
                'shares' => [
                    ['amount' => 500, 'label' => 'บอม'],
                    ['amount' => 500, 'label' => 'แนน'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_non_owner_cannot_setup_split(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $this->addActiveMember($booking, $friend);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertForbidden();
    }

    public function test_cannot_setup_split_without_outstanding_balance(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $booking->update(['balance_amount' => 0, 'balance_paid_at' => now(), 'paid_amount' => 4000]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertStatus(422);
    }

    public function test_cannot_setup_split_twice(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertStatus(422);
    }

    // ── ชำระส่วนแบ่งในแอป ─────────────────────────────────────

    public function test_member_pays_own_share_in_app(): void
    {
        Queue::fake();
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $passenger = $booking->passengers()->orderBy('id')->get()[1];
        $this->addActiveMember($booking, $friend, $passenger->id);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->where('passenger_id', $passenger->id)->first();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/pay", [
                'payment_method' => 'promptpay',
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'transfer_date' => now()->format('Y-m-d'),
                'transfer_time' => '14:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.split.paid_shares', 1);

        $share->refresh();
        $booking->refresh();

        $this->assertTrue($share->isPaid());
        $this->assertNotNull($share->slip_path);
        $this->assertEquals(2000.0, (float) $booking->paid_amount);
        $this->assertEquals(2000.0, (float) $booking->balance_amount);
        $this->assertNull($booking->balance_paid_at);

        Queue::assertPushed(VerifySlipJob::class);

        // เจ้าของได้รับแจ้งว่ามีคนจ่าย
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'split_share_paid',
        ]);
    }

    public function test_paying_last_share_settles_balance_and_notifies_everyone(): void
    {
        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $passengers = $booking->passengers()->orderBy('id')->get();
        $this->addActiveMember($booking, $friend, $passengers[1]->id);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        foreach ($booking->splitShares()->get() as $share) {
            $this->actingAs($owner, 'sanctum')
                ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/pay", [
                    'payment_method' => 'mobile_banking',
                ])
                ->assertOk();
        }

        $booking->refresh();
        $this->assertNotNull($booking->balance_paid_at);
        $this->assertEquals(4000.0, (float) $booking->paid_amount);
        $this->assertEquals(0.0, (float) $booking->balance_amount);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'split_all_paid',
        ]);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $friend->id,
            'type' => 'split_all_paid',
        ]);
    }

    public function test_share_cannot_be_paid_twice(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->first();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/pay")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/pay")
            ->assertStatus(422);
    }

    public function test_outsider_cannot_pay_share(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->first();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/pay")
            ->assertForbidden();
    }

    // ── แก้ไข / ยกเลิก ────────────────────────────────────────

    public function test_owner_can_update_pending_share_amounts(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $shares = $booking->splitShares()->get();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->booking_ref}/split", [
                'shares' => [
                    ['id' => $shares[0]->id, 'amount' => 500],
                    ['id' => $shares[1]->id, 'amount' => 1000],
                    ['id' => $shares[2]->id, 'amount' => 1500],
                ],
            ])
            ->assertOk();

        $this->assertEquals(500.0, (float) $shares[0]->fresh()->amount);
        $this->assertEquals(1500.0, (float) $shares[2]->fresh()->amount);
    }

    public function test_update_can_drop_and_add_shares(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $shares = $booking->splitShares()->get();

        // ยุบเหลือ 2 ส่วน: คงส่วนแรกไว้ + เพิ่มส่วนใหม่ (ลบ 2 ส่วนเดิม)
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->booking_ref}/split", [
                'shares' => [
                    ['id' => $shares[0]->id, 'amount' => 1200],
                    ['label' => 'คนใหม่', 'amount' => 1800],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.total_shares', 2);

        $this->assertDatabaseMissing('booking_split_shares', ['id' => $shares[1]->id]);
        $this->assertDatabaseHas('booking_split_shares', ['booking_id' => $booking->id, 'label' => 'คนใหม่']);
    }

    public function test_cancel_split_removes_only_pending_shares(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->first();
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/pay")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertOk();

        $remaining = $booking->splitShares()->get();
        $this->assertCount(1, $remaining);
        $this->assertTrue($remaining->first()->isPaid());
    }

    // ── เตือนสมาชิก ───────────────────────────────────────────

    public function test_owner_can_remind_member_with_throttle(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $passenger = $booking->passengers()->orderBy('id')->get()[1];
        $this->addActiveMember($booking, $friend, $passenger->id);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->where('passenger_id', $passenger->id)->first();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/remind")
            ->assertOk();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $friend->id,
            'type' => 'split_share_reminder',
        ]);

        // เตือนซ้ำภายใน 1 ชั่วโมงไม่ได้
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split/shares/{$share->id}/remind")
            ->assertStatus(422);
    }

    // ── ลิงก์เว็บสาธารณะ ──────────────────────────────────────

    public function test_public_share_page_shows_qr_and_amount(): void
    {
        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->first();

        $this->get('/pay-share/'.$share->pay_token)
            ->assertOk()
            ->assertSee($booking->booking_ref)
            ->assertSee('ชำระส่วนของคุณ')
            ->assertSee('data:image/svg+xml');
    }

    public function test_public_share_submit_records_payment(): void
    {
        Queue::fake();
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        $share = $booking->splitShares()->first();

        $this->post('/pay-share/'.$share->pay_token, [
            'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            'payment_method' => 'promptpay',
            'transfer_datetime' => now()->format('Y-m-d\TH:i'),
        ])->assertRedirect('/pay-share/'.$share->pay_token);

        $share->refresh();
        $this->assertTrue($share->isPaid());
        Storage::disk(MediaDisk::slipDisk())->assertExists($share->slip_path);
        Queue::assertPushed(VerifySlipJob::class);

        // เปิดซ้ำเห็นหน้าชำระแล้ว
        $this->get('/pay-share/'.$share->pay_token)
            ->assertOk()
            ->assertSee('ชำระส่วนของคุณแล้ว');
    }

    // ── จ่ายเต็มแบบแบ่งจ่าย (ตอนจอง) ──────────────────────────

    public function test_charge_split_confirms_booking_with_owner_share(): void
    {
        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => 3000,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => "Passenger {$i}",
            ]);
        }

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'split',
                'payment_method' => 'promptpay',
                'amount' => 1000,
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);
        $this->assertEquals('deposit', $booking->payment_type);
        $this->assertEquals(1000.0, (float) $booking->deposit_amount);
        $this->assertEquals(1000.0, (float) $booking->paid_amount);
        $this->assertEquals(2000.0, (float) $booking->balance_amount);
        $this->assertNotNull($booking->balance_due_at);

        // สร้างส่วนแบ่งให้เพื่อนอีก 2 คน
        $shares = $booking->splitShares()->get();
        $this->assertCount(2, $shares);
        $this->assertEquals(2000.0, (float) $shares->sum('amount'));

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'split_started',
        ]);
    }

    public function test_charge_split_requires_at_least_two_passengers(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => 3000,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Solo']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'split',
                'amount' => 3000,
            ])
            ->assertStatus(422);
    }

    // ── รับคำเชิญแล้วผูกส่วนแบ่งอัตโนมัติ ─────────────────────

    public function test_accepting_invite_links_member_to_unassigned_share(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $booking = $this->makeDepositBooking($owner);
        $passenger = $booking->passengers()->orderBy('id')->get()[1];

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/split")
            ->assertCreated();

        // เจ้าของสร้างคำเชิญผูกกับ passenger คนที่สอง
        $token = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/invites", [
                'passenger_id' => $passenger->id,
            ])
            ->assertCreated()
            ->json('data.invite_token');

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")
            ->assertOk();

        $share = $booking->splitShares()->where('passenger_id', $passenger->id)->first();
        $member = BookingMember::where('booking_id', $booking->id)->where('user_id', $friend->id)->first();

        $this->assertEquals($member->id, $share->member_id);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $friend->id,
            'type' => 'split_share_created',
        ]);
    }
}
