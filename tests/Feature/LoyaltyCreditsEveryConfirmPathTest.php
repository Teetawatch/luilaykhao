<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Support\LoyaltyTier;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * การจองต้องเข้าบัญชีสมาชิกไม่ว่าจะถูกยืนยันด้วยทางไหน
 *
 * ของเดิมให้แต้มเฉพาะตอนที่ผ่าน BookingService::confirmBooking() ลูกค้าที่โทรมา
 * จองแล้วแอดมินคีย์ให้ (ซึ่งคือกลุ่มขาประจำ) จึงไม่เคยได้แต้มและไม่เคยได้ระดับ
 * สมาชิกเลย — ป้ายฉายาบนแอปจึงไม่ขึ้นสักที
 */
class LoyaltyCreditsEveryConfirmPathTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['loyalty.baht_per_point' => 100]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปทดสอบ', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 20, 'price_per_person' => 3500, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function pendingBooking(User $user, TripSchedule $schedule, float $total = 3500): Booking
    {
        return Booking::create([
            'booking_ref' => 'LLK-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'pending',
            'total_amount' => $total,
        ]);
    }

    public function test_admin_flipping_the_status_credits_the_customer(): void
    {
        $customer = User::factory()->create();
        $booking = $this->pendingBooking($customer, $this->makeSchedule());

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/bookings/{$booking->booking_ref}/status", [
                'status' => 'confirmed',
            ])
            ->assertOk();

        $account = LoyaltyAccount::forUser($customer->id);
        $this->assertSame(35, $account->points);
        $this->assertSame(1, (int) $account->lifetime_trips);
    }

    public function test_a_manual_booking_keyed_in_as_confirmed_credits_the_customer(): void
    {
        Storage::fake(MediaDisk::slipDisk());
        $schedule = $this->makeSchedule();

        // ใบจองถูกสร้างเป็น confirmed มาตั้งแต่แถวแรก ไม่มีการเปลี่ยนสถานะให้จับ
        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'ลูกค้าโทรมาจอง',
                'phone' => '0800000001',
                'passenger_count' => 1,
                'status' => 'confirmed',
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'transfer_date' => now('Asia/Bangkok')->toDateString(),
                'transfer_time' => '10:30',
            ])
            ->assertCreated();

        $customer = User::where('phone', '0800000001')->firstOrFail();
        $account = LoyaltyAccount::forUser($customer->id);

        $this->assertSame(35, $account->points);
        $this->assertSame(1, (int) $account->lifetime_trips);
    }

    public function test_an_unpaid_manual_booking_earns_nothing_until_it_is_confirmed(): void
    {
        $schedule = $this->makeSchedule();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'ลูกค้ารอโอน',
                'phone' => '0800000002',
                'passenger_count' => 1,
                'status' => 'pending',
            ])
            ->assertCreated();

        $customer = User::where('phone', '0800000002')->firstOrFail();
        $this->assertSame(0, LoyaltyAccount::forUser($customer->id)->points);

        Booking::where('user_id', $customer->id)->firstOrFail()->update(['status' => 'confirmed']);

        $this->assertSame(35, LoyaltyAccount::forUser($customer->id)->points);
    }

    public function test_paying_through_the_normal_flow_still_credits_exactly_once(): void
    {
        $customer = User::factory()->create();
        $booking = $this->pendingBooking($customer, $this->makeSchedule());

        app(BookingService::class)->confirmBooking($booking, 'promptpay', 'REF-1', 3500);

        $account = LoyaltyAccount::forUser($customer->id);
        $this->assertSame(35, $account->points);
        $this->assertSame(1, (int) $account->lifetime_trips);
        $this->assertSame(1, $account->transactions()->where('type', 'earn')->count());
    }

    public function test_cancelling_a_trip_takes_the_credit_back(): void
    {
        $customer = User::factory()->create();
        $booking = $this->pendingBooking($customer, $this->makeSchedule());

        $booking->update(['status' => 'confirmed']);
        $booking->update(['status' => 'cancelled']);

        $account = LoyaltyAccount::forUser($customer->id);
        $this->assertSame(0, (int) $account->lifetime_trips);
        $this->assertSame(0, $account->points);
        $this->assertSame(LoyaltyTier::FRIEND, $account->tier);
        // ประวัติต้องอธิบายได้ว่าแต้มหายไปไหน
        $this->assertSame(1, $account->transactions()->where('type', 'adjust')->count());
    }

    public function test_cancelling_and_reconfirming_counts_the_trip_once(): void
    {
        $customer = User::factory()->create();
        $booking = $this->pendingBooking($customer, $this->makeSchedule());

        $booking->update(['status' => 'confirmed']);
        $booking->update(['status' => 'cancelled']);
        $booking->update(['status' => 'confirmed']);

        $account = LoyaltyAccount::forUser($customer->id);
        $this->assertSame(1, (int) $account->lifetime_trips);
        $this->assertSame(35, $account->points);
        $this->assertSame(1, $account->transactions()->where('type', 'earn')->count());
    }

    public function test_taking_back_points_never_pushes_the_balance_negative(): void
    {
        $customer = User::factory()->create();
        $booking = $this->pendingBooking($customer, $this->makeSchedule());
        $booking->update(['status' => 'confirmed']);

        // ลูกค้าแลกแต้มไปแล้วก่อนจะยกเลิกทริป
        $account = LoyaltyAccount::forUser($customer->id);
        $account->update(['points' => 5]);

        $booking->update(['status' => 'cancelled']);

        $this->assertSame(0, LoyaltyAccount::forUser($customer->id)->points);
    }

    public function test_two_trips_earn_the_regular_badge(): void
    {
        $customer = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->pendingBooking($customer, $schedule)->update(['status' => 'confirmed']);
        $this->assertSame(LoyaltyTier::FRIEND, LoyaltyAccount::forUser($customer->id)->tier);

        $this->pendingBooking($customer, $schedule)->update(['status' => 'confirmed']);

        $account = LoyaltyAccount::forUser($customer->id);
        $this->assertSame(LoyaltyTier::FREQUENT, $account->tier);
        $this->assertSame('ขาประจำ', LoyaltyTier::label($account->tier));

        // ป้ายต้องเดินทางไปกับผู้ใช้ในที่ที่คนอื่นเห็นด้วย
        $this->assertSame('ขาประจำ', $customer->fresh()->tierBadge()['tier_label']);
    }

    public function test_a_free_booking_still_counts_as_a_trip(): void
    {
        $customer = User::factory()->create();
        $booking = $this->pendingBooking($customer, $this->makeSchedule(), total: 0);

        $booking->update(['status' => 'confirmed']);

        $account = LoyaltyAccount::forUser($customer->id);
        $this->assertSame(0, $account->points);
        $this->assertSame(1, (int) $account->lifetime_trips);
    }
}
