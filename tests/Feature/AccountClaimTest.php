<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\AccountClaimService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "แอดมินจองให้ แล้วลูกค้าสมัครเอง — การจองไม่ขึ้นในแอป"
 *
 * ครอบทั้งการกันไม่ให้เกิดบัญชีซ้ำตั้งแต่ต้นทาง และสองประตูที่ใช้ตามเก็บใบจอง
 * ที่ค้างอยู่ในบัญชีเงา (เลขที่จอง+เบอร์ / ลิงก์เปิดใช้บัญชี)
 */
class AccountClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปดอยหลวง', 'slug' => 'claim-'.uniqid(), 'type' => 'trekking',
            'location' => 'Chiang Rai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /** จองแทนลูกค้าแบบที่แอดมินทำจริง — ไม่กรอกอีเมล เพราะยังไม่มีของลูกค้า */
    private function bookManually(TripSchedule $schedule, array $overrides = []): Booking
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/bookings/manual', array_merge([
            'schedule_id' => $schedule->id,
            'customer_name' => 'สมชาย ใจดี',
            'phone' => '081-234-5678',
            'status' => 'pending',
            'payment_type' => 'full',
            'hold_until' => now()->addDays(3)->toDateString(),
            'send_email' => false,
            'passengers' => [[
                'title' => 'นาย', 'name' => 'สมชาย ใจดี', 'phone' => '0812345678',
            ]],
        ], $overrides));

        $response->assertCreated();

        return Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
    }

    public function test_manual_booking_creates_a_flagged_shadow_account(): void
    {
        $booking = $this->bookManually($this->makeSchedule());

        $this->assertTrue($booking->user->is_shadow);
        $this->assertTrue($booking->user->hasPlaceholderEmail());
    }

    public function test_manual_booking_reuses_the_customers_real_account_when_the_phone_matches(): void
    {
        $customer = User::factory()->create([
            'email' => 'somchai@gmail.com',
            'phone' => '0812345678',
        ]);

        $booking = $this->bookManually($this->makeSchedule());

        $this->assertSame($customer->id, $booking->user_id);
        $this->assertFalse($booking->user->is_shadow);
        $this->assertSame(1, User::whereIn('phone', PhoneNumber::variants('0812345678'))->count());
    }

    public function test_manual_booking_never_overwrites_a_real_accounts_email(): void
    {
        $customer = User::factory()->create(['email' => 'somchai@gmail.com', 'phone' => '0812345678']);

        $this->bookManually($this->makeSchedule(), ['email' => 'typo@gmail.com']);

        $this->assertSame('somchai@gmail.com', $customer->fresh()->email);
    }

    public function test_manual_booking_matches_an_existing_account_regardless_of_email_case(): void
    {
        $customer = User::factory()->create(['email' => 'somchai@gmail.com', 'phone' => '0899999999']);

        $booking = $this->bookManually($this->makeSchedule(), ['email' => 'SomChai@Gmail.com']);

        $this->assertSame($customer->id, $booking->user_id);
    }

    public function test_manual_booking_texts_the_customer_an_account_claim_link(): void
    {
        $booking = $this->bookManually($this->makeSchedule());

        $this->assertDatabaseHas('sms_logs', [
            'booking_id' => $booking->id,
            'sms_type' => 'account_claim',
        ]);
        $this->assertNotNull($booking->user->fresh()->claim_token);
    }

    public function test_customer_who_registered_separately_is_told_a_booking_is_waiting(): void
    {
        $booking = $this->bookManually($this->makeSchedule());
        $customer = User::factory()->create(['phone' => '0812345678']);

        $response = $this->actingAs($customer, 'sanctum')->getJson('/api/v1/me/claimable-bookings');

        $response->assertOk()->assertJsonPath('data.count', 1);
        // ห้ามหลุดรายละเอียดใบจองจากการเดาเบอร์ถูก
        $response->assertJsonMissing(['booking_ref' => $booking->booking_ref]);
    }

    public function test_customer_claims_the_booking_with_reference_and_phone(): void
    {
        $booking = $this->bookManually($this->makeSchedule());
        $customer = User::factory()->create(['phone' => '0812345678']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/bookings/claim', [
                'booking_ref' => $booking->booking_ref,
                'phone' => '5678',
            ])
            ->assertOk();

        $this->assertSame($customer->id, $booking->fresh()->user_id);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.booking_ref', $booking->booking_ref);
    }

    public function test_claim_is_refused_when_the_phone_does_not_match(): void
    {
        $booking = $this->bookManually($this->makeSchedule());
        $customer = User::factory()->create(['phone' => '0899999999']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/bookings/claim', [
                'booking_ref' => $booking->booking_ref,
                'phone' => '1111',
            ])
            ->assertStatus(422);

        $this->assertNotSame($customer->id, $booking->fresh()->user_id);
    }

    public function test_a_booking_owned_by_a_real_account_cannot_be_claimed_by_someone_else(): void
    {
        $owner = User::factory()->create(['phone' => '0812345678', 'email' => 'owner@gmail.com']);
        $booking = $this->bookManually($this->makeSchedule());
        $this->assertSame($owner->id, $booking->user_id);

        $stranger = User::factory()->create(['phone' => '0812345678']);

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/v1/bookings/claim', [
                'booking_ref' => $booking->booking_ref,
                'phone' => '5678',
            ])
            ->assertStatus(422);

        $this->assertSame($owner->id, $booking->fresh()->user_id);
    }

    public function test_claim_link_turns_the_shadow_account_into_a_real_one(): void
    {
        $booking = $this->bookManually($this->makeSchedule());
        $token = app(AccountClaimService::class)->issueClaimToken($booking->user);

        $this->get('/claim/'.$token)->assertOk()->assertSee($booking->booking_ref);

        $this->post('/claim/'.$token, [
            'email' => 'Somchai@Gmail.com',
            'password' => 'secret1234',
        ])->assertRedirect(route('public.claim.done'));

        $user = $booking->fresh()->user;
        $this->assertSame('somchai@gmail.com', $user->email);
        $this->assertFalse($user->is_shadow);
        $this->assertNull($user->claim_token);
        $this->assertTrue(Hash::check('secret1234', $user->password));

        $this->postJson('/api/v1/auth/login', ['email' => 'somchai@gmail.com', 'password' => 'secret1234'])
            ->assertOk();
    }

    public function test_claim_link_merges_into_the_account_the_customer_already_registered(): void
    {
        $booking = $this->bookManually($this->makeSchedule());
        $shadow = $booking->user;
        $token = app(AccountClaimService::class)->issueClaimToken($shadow);

        $customer = User::factory()->create([
            'email' => 'somchai@gmail.com',
            'password' => Hash::make('secret1234'),
        ]);

        $this->post('/claim/'.$token, [
            'email' => 'somchai@gmail.com',
            'password' => 'secret1234',
        ])->assertRedirect(route('public.claim.done'));

        $this->assertSame($customer->id, $booking->fresh()->user_id);
        $this->assertFalse($shadow->fresh()->is_shadow);
        $this->assertNull($shadow->fresh()->claim_token);
    }

    public function test_claim_link_refuses_a_taken_email_without_its_password(): void
    {
        $booking = $this->bookManually($this->makeSchedule());
        $token = app(AccountClaimService::class)->issueClaimToken($booking->user);
        User::factory()->create(['email' => 'somchai@gmail.com', 'password' => Hash::make('the-real-one')]);

        $this->from('/claim/'.$token)
            ->post('/claim/'.$token, ['email' => 'somchai@gmail.com', 'password' => 'guessing123'])
            ->assertRedirect('/claim/'.$token)
            ->assertSessionHasErrors('email');

        $this->assertTrue($booking->fresh()->user->is_shadow);
    }

    public function test_admin_can_resend_the_claim_link(): void
    {
        $booking = $this->bookManually($this->makeSchedule());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->booking_ref.'/claim-link')
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref);

        $this->assertSame(2, \DB::table('sms_logs')
            ->where('booking_id', $booking->id)
            ->where('sms_type', 'account_claim')
            ->count());
    }

    public function test_the_backlog_command_texts_only_shadow_accounts_that_never_got_a_link(): void
    {
        // ใบที่เพิ่งเปิดได้ลิงก์ไปแล้วตอนสร้าง — คำสั่งตามเก็บต้องข้ามใบนี้
        $alreadySent = $this->bookManually($this->makeSchedule());

        // ใบเก่าที่ค้างมาก่อนมีระบบนี้: มีบัญชีเงาแต่ยังไม่เคยได้ลิงก์
        $stale = $this->bookManually($this->makeSchedule(), ['phone' => '0898888888']);
        $stale->user->forceFill(['claim_token_sent_at' => null])->save();
        \DB::table('sms_logs')->where('booking_id', $stale->id)->delete();

        $this->artisan('bookings:send-claim-links')->assertSuccessful();

        $this->assertDatabaseHas('sms_logs', ['booking_id' => $stale->id, 'sms_type' => 'account_claim']);
        $this->assertSame(1, \DB::table('sms_logs')
            ->where('booking_id', $alreadySent->id)
            ->where('sms_type', 'account_claim')
            ->count());
    }

    public function test_admin_resend_is_refused_once_the_customer_owns_the_account(): void
    {
        $customer = User::factory()->create(['phone' => '0812345678', 'email' => 'somchai@gmail.com']);
        $booking = $this->bookManually($this->makeSchedule());
        $this->assertSame($customer->id, $booking->user_id);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->booking_ref.'/claim-link')
            ->assertStatus(422);
    }
}
