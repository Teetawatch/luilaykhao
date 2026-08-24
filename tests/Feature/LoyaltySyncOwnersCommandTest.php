<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ซ่อมใบจองที่เปลี่ยนมือไปก่อนที่ BookingObserver จะรู้จักการเปลี่ยนเจ้าของ
 *
 * ของเดิมการโอนใบจองเขียนแค่ `bookings.user_id` แต้มกับทริปสะสมจึงค้างอยู่กับ
 * บัญชีเดิม และ `loyalty:backfill` ก็ข้ามใบจองพวกนี้เพราะมันเคยได้แต้มไปแล้ว
 */
class LoyaltySyncOwnersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['loyalty.baht_per_point' => 100]);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปทดสอบ', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 20, 'price_per_person' => 2000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /** ใบจองที่ถูกโอนแบบยุคก่อน — เขียน user_id ตรง ๆ โดยไม่ยิง observer. */
    private function bookingTransferredTheOldWay(User $from, User $to): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $from->id,
            'schedule_id' => $this->makeSchedule()->id,
            'status' => 'confirmed',
            'total_amount' => 2000,
        ]);

        Booking::where('id', $booking->id)->update(['user_id' => $to->id]);

        return $booking->fresh();
    }

    public function test_it_moves_stranded_credit_to_the_current_owner(): void
    {
        $old = User::factory()->create();
        $new = User::factory()->create();
        $booking = $this->bookingTransferredTheOldWay($old, $new);

        // สถานะก่อนซ่อม: แต้มยังอยู่กับบัญชีเดิม
        $this->assertSame(1, (int) LoyaltyAccount::forUser($old->id)->lifetime_trips);
        $this->assertSame(0, (int) LoyaltyAccount::forUser($new->id)->lifetime_trips);

        $this->artisan('loyalty:sync-owners')
            ->expectsOutputToContain($booking->booking_ref)
            ->assertSuccessful();

        $this->assertSame(0, (int) LoyaltyAccount::forUser($old->id)->lifetime_trips);
        $this->assertSame(0, (int) LoyaltyAccount::forUser($old->id)->points);
        $this->assertSame(1, (int) LoyaltyAccount::forUser($new->id)->lifetime_trips);
        $this->assertSame(20, (int) LoyaltyAccount::forUser($new->id)->points);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $old = User::factory()->create();
        $new = User::factory()->create();
        $this->bookingTransferredTheOldWay($old, $new);

        $this->artisan('loyalty:sync-owners', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, (int) LoyaltyAccount::forUser($old->id)->lifetime_trips);
        $this->assertSame(0, (int) LoyaltyAccount::forUser($new->id)->lifetime_trips);
    }

    public function test_running_it_twice_does_not_double_credit(): void
    {
        $old = User::factory()->create();
        $new = User::factory()->create();
        $this->bookingTransferredTheOldWay($old, $new);

        $this->artisan('loyalty:sync-owners')->assertSuccessful();
        $this->artisan('loyalty:sync-owners')
            ->expectsOutputToContain('ไม่พบใบจองที่แต้มค้างอยู่ผิดบัญชี')
            ->assertSuccessful();

        $this->assertSame(1, (int) LoyaltyAccount::forUser($new->id)->lifetime_trips);
        $this->assertSame(20, (int) LoyaltyAccount::forUser($new->id)->points);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'earn')->count());
    }

    public function test_it_leaves_bookings_whose_owner_never_changed_alone(): void
    {
        $owner = User::factory()->create();

        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $this->makeSchedule()->id,
            'status' => 'confirmed',
            'total_amount' => 2000,
        ]);

        $this->artisan('loyalty:sync-owners')
            ->expectsOutputToContain('ไม่พบใบจองที่แต้มค้างอยู่ผิดบัญชี')
            ->assertSuccessful();

        $this->assertSame(1, (int) LoyaltyAccount::forUser($owner->id)->lifetime_trips);
        $this->assertSame(20, (int) LoyaltyAccount::forUser($owner->id)->points);
    }

    /** แต้มที่เจ้าของเดิมแลกของรางวัลไปแล้วเรียกคืนไม่ได้ แต่ทริปสะสมต้องย้ายอยู่ดี. */
    public function test_points_already_spent_by_the_old_owner_are_reported_not_clawed_back(): void
    {
        $old = User::factory()->create();
        $new = User::factory()->create();
        $this->bookingTransferredTheOldWay($old, $new);

        app(LoyaltyService::class)->spend($old->id, 20, 'แลกของรางวัลทดสอบ');

        $this->artisan('loyalty:sync-owners')
            ->expectsOutputToContain('เรียกคืนไม่ได้')
            ->assertSuccessful();

        $this->assertSame(0, (int) LoyaltyAccount::forUser($old->id)->points);
        $this->assertSame(0, (int) LoyaltyAccount::forUser($old->id)->lifetime_trips);
        $this->assertSame(20, (int) LoyaltyAccount::forUser($new->id)->points);
        $this->assertSame(1, (int) LoyaltyAccount::forUser($new->id)->lifetime_trips);
    }
}
