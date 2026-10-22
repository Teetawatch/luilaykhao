<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PartnerService;
use App\Support\MediaDisk;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('operator', 'web');
        Role::findOrCreate('finance', 'web');
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function admin(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function partner(array $overrides = []): Partner
    {
        return Partner::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'code' => 'FRIEND1',
            'display_name' => 'เพจเที่ยวป่า',
            'commission_type' => Partner::TYPE_PERCENT,
            'commission_rate' => 10,
            'status' => Partner::STATUS_ACTIVE,
        ], $overrides));
    }

    private function makeSchedule(float $price = 2000, int $departInDays = 30): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปทดสอบพาร์ทเนอร์',
            'slug' => 'partner-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => $price,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($departInDays)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($departInDays + 1)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function passengers(int $count = 1): array
    {
        $ids = ['1234567890121', '1101700203451', '3100600123458'];

        return array_map(fn ($i) => [
            'title' => 'นาย',
            'name' => 'ผู้เดินทาง ทดสอบ',
            'nickname' => 'ทด',
            'id_card' => $ids[$i],
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ], range(0, $count - 1));
    }

    private function book(User $user, TripSchedule $schedule, array $extra = [], int $people = 1)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'schedule_id' => $schedule->id,
            'passengers' => $this->passengers($people),
        ], $extra));
    }

    private function bookViaPartner(?TripSchedule $schedule = null, string $code = 'FRIEND1', int $people = 1): Booking
    {
        $response = $this->book(User::factory()->create(), $schedule ?? $this->makeSchedule(), ['partner_code' => $code], $people);
        $response->assertCreated();

        return Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
    }

    private function confirm(Booking $booking): Booking
    {
        $booking->update([
            'status' => 'confirmed',
            'paid_amount' => $booking->total_amount,
            'paid_at' => now(),
        ]);

        return $booking->fresh();
    }

    private function commissionOf(Booking $booking): ?PartnerCommission
    {
        return PartnerCommission::where('booking_id', $booking->id)
            ->where('type', PartnerCommission::TYPE_COMMISSION)
            ->first();
    }

    private function afterTrip(): Carbon
    {
        return Carbon::now('Asia/Bangkok')->addDays(40);
    }

    // ── ผูกใบจอง ────────────────────────────────────────────────────

    public function test_a_booking_made_through_a_partner_link_is_attributed_but_earns_nothing_until_paid(): void
    {
        $partner = $this->partner();

        $booking = $this->bookViaPartner(code: 'friend1');

        $this->assertSame($partner->id, $booking->partner_id);
        $this->assertSame('link', $booking->partner_source);
        $this->assertNull($this->commissionOf($booking));
    }

    public function test_confirming_opens_a_pending_commission_on_trip_price_only_and_tells_the_partner(): void
    {
        $partner = $this->partner();
        $booking = $this->bookViaPartner(people: 2);

        // ค่าบริการเสริม/ค่าเช่าไม่นับเป็นฐานคอม
        $booking->update(['total_amount' => 4600, 'addons_total' => 400, 'rentals_total' => 200]);
        $this->confirm($booking);

        $commission = $this->commissionOf($booking);
        $this->assertSame(PartnerCommission::STATUS_PENDING, $commission->status);
        $this->assertSame(2, $commission->seats);
        $this->assertEquals(4000, (float) $commission->base_amount);
        $this->assertEquals(400, (float) $commission->amount);
        $this->assertSame('ทริปทดสอบพาร์ทเนอร์', $commission->trip_title);

        $this->assertDatabaseHas('smart_notifications', ['user_id' => $partner->user_id, 'type' => 'partner_sale']);
    }

    public function test_per_seat_commission_never_exceeds_what_the_company_actually_received(): void
    {
        $this->partner(['commission_type' => Partner::TYPE_PER_SEAT, 'commission_rate' => 300]);
        $booking = $this->bookViaPartner(people: 2);
        $this->confirm($booking);

        $this->assertEquals(600, (float) $this->commissionOf($booking)->amount);

        // ทีมงานยกเว้นค่าทริปให้ทั้งหมด → ไม่มีเงินเข้า จึงไม่มีคอม
        $free = $this->bookViaPartner(people: 2);
        $free->update(['waived_amount' => $free->total_amount]);
        $free->update(['status' => 'confirmed']);

        $this->assertEquals(0, (float) $this->commissionOf($free)->amount);
    }

    public function test_bad_paused_or_self_codes_never_block_the_booking_and_attribute_nothing(): void
    {
        $owner = User::factory()->create();
        $this->partner(['user_id' => $owner->id, 'code' => 'SELFIE']);
        $this->partner(['code' => 'SLEEPY', 'status' => Partner::STATUS_PAUSED]);
        $schedule = $this->makeSchedule();

        foreach (['NOPE99', 'SLEEPY'] as $code) {
            $this->book(User::factory()->create(), $schedule, ['partner_code' => $code])->assertCreated();
        }
        $this->book($owner, $schedule, ['partner_code' => 'SELFIE'])->assertCreated();

        $this->assertSame(0, Booking::whereNotNull('partner_id')->count());
    }

    public function test_a_booking_keeps_its_first_partner(): void
    {
        $first = $this->partner();
        $this->partner(['code' => 'OTHER2']);
        $booking = $this->bookViaPartner();

        $this->assertFalse(app(PartnerService::class)->attributeFromCode($booking, 'OTHER2'));
        $this->assertSame($first->id, $booking->fresh()->partner_id);
    }

    // ── อนุมัติ / ยกเลิก ─────────────────────────────────────────────

    public function test_commission_is_approved_only_after_the_round_returns(): void
    {
        $this->partner();
        $booking = $this->confirm($this->bookViaPartner());
        $service = app(PartnerService::class);

        // วันกลับยังไม่ผ่าน
        $this->assertSame(0, $service->approveDue(Carbon::now('Asia/Bangkok')->addDays(31)));
        $this->assertSame(PartnerCommission::STATUS_PENDING, $this->commissionOf($booking)->status);

        $this->assertSame(1, $service->approveDue($this->afterTrip()));
        $commission = $this->commissionOf($booking);
        $this->assertSame(PartnerCommission::STATUS_APPROVED, $commission->status);
        $this->assertNotNull($commission->approved_at);
    }

    public function test_approval_recomputes_from_the_booking_and_keeps_the_rate_it_was_sold_at(): void
    {
        $partner = $this->partner();
        $booking = $this->confirm($this->bookViaPartner());

        // ทีมงานขึ้นอัตราทีหลัง + ใบจองถูกลดยอดลง — ใบเก่าใช้อัตราเดิม ยอดใหม่
        $partner->update(['commission_rate' => 20]);
        $booking->update(['total_amount' => 1500, 'paid_amount' => 1500]);

        app(PartnerService::class)->approveDue($this->afterTrip());

        $this->assertEquals(150, (float) $this->commissionOf($booking)->amount);
    }

    public function test_an_unpaid_balance_holds_the_commission_back(): void
    {
        $this->partner();
        $booking = $this->bookViaPartner();
        $booking->update([
            'status' => 'confirmed',
            'payment_type' => 'deposit',
            'deposit_amount' => 500,
            'balance_amount' => 1500,
            'paid_amount' => 500,
        ]);

        $this->assertSame(0, app(PartnerService::class)->approveDue($this->afterTrip()));

        $booking->update(['balance_paid_at' => now(), 'paid_amount' => 2000]);
        $this->assertSame(1, app(PartnerService::class)->approveDue($this->afterTrip()));
    }

    public function test_cancelling_before_payout_voids_the_commission(): void
    {
        $this->partner();
        $booking = $this->confirm($this->bookViaPartner());

        app(BookingService::class)->cancelBooking($booking, 'ลูกค้าไม่สะดวก', notify: false);

        $commission = $this->commissionOf($booking);
        $this->assertSame(PartnerCommission::STATUS_VOID, $commission->status);
        $this->assertSame('ยกเลิกการจอง', $commission->void_reason);
        $this->assertSame(0, app(PartnerService::class)->approveDue($this->afterTrip()));
    }

    // ── จ่ายคอม ─────────────────────────────────────────────────────

    public function test_admin_pays_out_every_approved_commission_in_one_transfer(): void
    {
        $partner = $this->partner();
        $schedule = $this->makeSchedule();
        $a = $this->confirm($this->bookViaPartner($schedule));
        $b = $this->confirm($this->bookViaPartner($schedule));
        $stillTravelling = $this->confirm($this->bookViaPartner($this->makeSchedule(departInDays: 60)));
        app(PartnerService::class)->approveDue($this->afterTrip());

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/partners/'.$partner->id.'/payouts', [
                'reference' => 'KBANK 22/10',
                'slip' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.payout.amount', 400)
            ->assertJsonPath('data.payout.commission_count', 2);

        $payout = PartnerPayout::firstOrFail();
        $this->assertStringStartsWith('slips/partner-payouts/', $payout->slip_path);
        $this->assertSame(PartnerCommission::STATUS_PAID, $this->commissionOf($a)->status);
        $this->assertSame($payout->id, $this->commissionOf($b)->payout_id);
        $this->assertSame(PartnerCommission::STATUS_PENDING, $this->commissionOf($stillTravelling)->status);
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $partner->user_id, 'type' => 'partner_payout']);

        // ไม่มีอะไรเหลือให้จ่ายแล้ว
        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/partners/'.$partner->id.'/payouts')
            ->assertStatus(422);
    }

    public function test_paying_out_needs_admin_or_finance(): void
    {
        $partner = $this->partner();
        $this->confirm($this->bookViaPartner());
        app(PartnerService::class)->approveDue($this->afterTrip());

        $this->actingAs($this->admin('operator'), 'sanctum')
            ->post('/api/v1/admin/partners/'.$partner->id.'/payouts')
            ->assertForbidden();

        $financeOperator = $this->admin('operator');
        $financeOperator->assignRole('finance');

        $this->actingAs($financeOperator, 'sanctum')
            ->post('/api/v1/admin/partners/'.$partner->id.'/payouts')
            ->assertOk();
    }

    public function test_a_refund_after_payout_is_clawed_back_from_the_next_transfer(): void
    {
        $partner = $this->partner();
        $schedule = $this->makeSchedule();
        $refunded = $this->confirm($this->bookViaPartner($schedule));
        $service = app(PartnerService::class);
        $service->approveDue($this->afterTrip());
        $service->recordPayout($partner, $this->admin(), []);

        app(BookingService::class)->processRefund($refunded->fresh(), 2000, 'ชดเชย');

        $clawback = PartnerCommission::where('booking_id', $refunded->id)
            ->where('type', PartnerCommission::TYPE_CLAWBACK)->firstOrFail();
        $this->assertEquals(-200, (float) $clawback->amount);
        $this->assertSame(PartnerCommission::STATUS_APPROVED, $clawback->status);

        // ยอดหักคืนอย่างเดียวจ่ายไม่ได้
        try {
            $service->recordPayout($partner, $this->admin(), []);
            $this->fail('A payout with only a clawback should be refused');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ยังไม่มียอดคอม', $e->getMessage());
        }

        // ขายใบใหม่ได้ 300 → โอนรอบถัดไป 300 − 200 = 100
        $next = $this->bookViaPartner($schedule);
        $next->update(['total_amount' => 3000]);
        $this->confirm($next);
        $service->approveDue($this->afterTrip());

        $payout = $service->recordPayout($partner, $this->admin(), []);
        $this->assertEquals(100, (float) $payout->amount);
        $this->assertSame(1, $payout->commission_count);
    }

    // ── ทีมงานผูกใบจอง ──────────────────────────────────────────────

    public function test_admin_can_attach_change_and_remove_a_partner_on_a_booking(): void
    {
        $first = $this->partner();
        $second = $this->partner(['code' => 'SECOND']);
        $schedule = $this->makeSchedule();
        $response = $this->book(User::factory()->create(), $schedule)->assertCreated();
        $booking = $this->confirm(Booking::where('booking_ref', $response->json('data.booking_ref'))->first());
        $admin = $this->admin('operator');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/bookings/'.$booking->booking_ref.'/partner', ['partner_id' => $first->id])
            ->assertOk();
        $this->assertSame($first->id, $this->commissionOf($booking)->partner_id);
        $this->assertSame('admin', $booking->fresh()->partner_source);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/bookings/'.$booking->booking_ref.'/partner', ['partner_id' => $second->id])
            ->assertOk();
        $this->assertSame($second->id, $this->commissionOf($booking)->partner_id);
        $this->assertSame(1, PartnerCommission::count());

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/bookings/'.$booking->booking_ref.'/partner', ['partner_id' => null])
            ->assertOk();
        $this->assertNull($booking->fresh()->partner_id);
        $this->assertSame(0, PartnerCommission::count());
    }

    public function test_a_paid_commission_cannot_be_moved_to_another_partner(): void
    {
        $partner = $this->partner();
        $other = $this->partner(['code' => 'OTHER2']);
        $booking = $this->confirm($this->bookViaPartner());
        app(PartnerService::class)->approveDue($this->afterTrip());
        app(PartnerService::class)->recordPayout($partner, $this->admin(), []);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/bookings/'.$booking->booking_ref.'/partner', ['partner_id' => $other->id])
            ->assertStatus(422);
    }

    public function test_admin_can_void_an_unpaid_commission_with_a_reason(): void
    {
        $this->partner();
        $booking = $this->confirm($this->bookViaPartner());
        $commission = $this->commissionOf($booking);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/partner-commissions/'.$commission->id.'/void', [])
            ->assertStatus(422);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/partner-commissions/'.$commission->id.'/void', ['reason' => 'จองซ้ำ'])
            ->assertOk()
            ->assertJsonPath('data.status', 'void');
    }

    // ── หลังบ้าน ────────────────────────────────────────────────────

    public function test_admin_opens_a_partner_account_with_a_generated_code(): void
    {
        $user = User::factory()->create(['name' => 'สมหญิง']);

        $response = $this->actingAs($this->admin('operator'), 'sanctum')
            ->postJson('/api/v1/admin/partners', [
                'user_id' => $user->id,
                'commission_type' => 'percent',
                'commission_rate' => 8,
                'payout_account' => ['bank' => 'กสิกร', 'number' => '123-4-56789-0', 'name' => 'สมหญิง'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'สมหญิง')
            ->assertJsonPath('data.rate_label', '8%');

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $response->json('data.code'));
        $this->assertStringContainsString('?partner='.$response->json('data.code'), $response->json('data.share_url'));

        // บัญชีเดิมเปิดซ้ำไม่ได้ · % เกิน 50 ถือว่าพิมพ์ผิด
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/partners', ['user_id' => $user->id, 'commission_type' => 'percent', 'commission_rate' => 5])
            ->assertStatus(422);
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/partners', ['user_id' => User::factory()->create()->id, 'commission_type' => 'percent', 'commission_rate' => 200])
            ->assertStatus(422);
    }

    public function test_admin_sees_the_partner_on_booking_detail_but_customers_do_not(): void
    {
        $this->partner();
        $booking = $this->confirm($this->bookViaPartner());

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings/'.$booking->booking_ref)
            ->assertJsonPath('data.partner.code', 'FRIEND1')
            ->assertJsonPath('data.partner.commission.status', 'pending');

        $this->actingAs($booking->user, 'sanctum')
            ->getJson('/api/v1/bookings/'.$booking->booking_ref)
            ->assertOk()
            ->assertJsonMissingPath('data.partner');
    }

    // ── หน้าของพาร์ทเนอร์ ───────────────────────────────────────────

    public function test_partner_dashboard_shows_money_but_no_customer_details(): void
    {
        $partner = $this->partner();
        $booking = $this->confirm($this->bookViaPartner());

        $response = $this->actingAs($partner->user, 'sanctum')
            ->getJson('/api/v1/partner')
            ->assertOk()
            ->assertJsonPath('data.code', 'FRIEND1')
            ->assertJsonPath('data.summary.pending', 200)
            ->assertJsonPath('data.summary.bookings', 1)
            ->assertJsonPath('data.recent_commissions.0.booking_ref', $booking->booking_ref);

        $this->assertStringNotContainsString('ผู้เดินทาง ทดสอบ', $response->getContent());
        $this->assertStringNotContainsString('0812345678', $response->getContent());

        $this->actingAs($partner->user, 'sanctum')
            ->getJson('/api/v1/partner/commissions?status=pending')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($partner->user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.is_partner', true);
    }

    public function test_non_partners_get_null_and_cannot_list_commissions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/partner')->assertOk()->assertJsonPath('data', null);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/partner/commissions')->assertNotFound();
    }

    public function test_partner_saves_a_payout_account_and_only_sees_it_masked(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner->user, 'sanctum')
            ->putJson('/api/v1/partner/payout-account', ['bank' => 'ไทยพาณิชย์', 'number' => '987-6-54321-0', 'name' => 'เพจเที่ยวป่า'])
            ->assertOk()
            ->assertJsonPath('data.payout_account.number_masked', 'xxx-3210');

        $this->assertSame('987-6-54321-0', $partner->fresh()->payout_account['number']);
    }

    public function test_the_booking_form_can_check_a_code_without_logging_in(): void
    {
        $this->partner();

        $this->getJson('/api/v1/partners/check?code=friend1')
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.display_name', 'เพจเที่ยวป่า');

        $this->getJson('/api/v1/partners/check?code=nope')->assertJsonPath('data.valid', false);
    }
}
