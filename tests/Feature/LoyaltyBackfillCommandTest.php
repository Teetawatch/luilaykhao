<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\LoyaltyTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `loyalty:backfill` ต้องไล่เก็บการจองเก่าที่ไม่เคยได้แต้ม — ทั้งการจองก่อนระบบ
 * สะสมแต้มจะมีอยู่ และการจองที่แอดมินยืนยันเองซึ่งไม่เคยผ่านจุดให้แต้มเลย
 */
class LoyaltyBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['loyalty.baht_per_point' => 100]);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปเก่า', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'น่าน', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 20, 'price_per_person' => 3500, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->subMonths(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subMonths(3)->addDay()->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'closed',
        ]);
    }

    /**
     * การจองยุคก่อนระบบแต้ม — สร้างแล้วลบร่องรอยการให้แต้มทิ้ง เพื่อจำลองแถวที่
     * ไม่เคยผ่าน LoyaltyService มาก่อน
     */
    private function legacyBooking(User $user, TripSchedule $schedule, float $total = 3500): Booking
    {
        $booking = Booking::create([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => $total,
            'paid_amount' => $total,
        ]);

        LoyaltyTransaction::where('reference_type', Booking::class)
            ->where('reference_id', $booking->id)
            ->delete();
        LoyaltyAccount::where('user_id', $user->id)->delete();

        return $booking;
    }

    public function test_it_credits_old_bookings_and_promotes_the_tier(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->legacyBooking($user, $schedule);
        $this->legacyBooking($user, $schedule);

        $this->artisan('loyalty:backfill')->assertSuccessful();

        $account = LoyaltyAccount::forUser($user->id);
        $this->assertSame(2, (int) $account->lifetime_trips);
        $this->assertSame(70, $account->points);
        $this->assertSame(LoyaltyTier::FREQUENT, $account->tier);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->legacyBooking($user, $schedule);
        $this->legacyBooking($user, $schedule);

        $this->artisan('loyalty:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('loyalty_transactions', 0);
        $this->assertSame(0, LoyaltyAccount::where('user_id', $user->id)->count());
    }

    public function test_running_it_twice_does_not_double_credit(): void
    {
        $user = User::factory()->create();
        $this->legacyBooking($user, $this->makeSchedule());

        $this->artisan('loyalty:backfill')->assertSuccessful();
        $this->artisan('loyalty:backfill')->assertSuccessful();

        $account = LoyaltyAccount::forUser($user->id);
        $this->assertSame(1, (int) $account->lifetime_trips);
        $this->assertSame(35, $account->points);
    }

    public function test_cancelled_bookings_are_not_counted(): void
    {
        $user = User::factory()->create();
        $booking = $this->legacyBooking($user, $this->makeSchedule());
        $booking->update(['status' => 'cancelled']);

        // การยกเลิกก่อนหน้านี้อาจให้แต้มไปแล้ว — ล้างให้เหลือสภาพ "ไม่เคยได้"
        LoyaltyTransaction::query()->delete();
        LoyaltyAccount::query()->delete();

        $this->artisan('loyalty:backfill')->assertSuccessful();

        $this->assertSame(0, LoyaltyAccount::forUser($user->id)->points);
        $this->assertSame(0, (int) LoyaltyAccount::forUser($user->id)->lifetime_trips);
    }

    public function test_it_can_be_limited_to_a_single_user_first(): void
    {
        $schedule = $this->makeSchedule();
        $target = User::factory()->create();
        $other = User::factory()->create();

        $this->legacyBooking($target, $schedule);
        $this->legacyBooking($other, $schedule);

        $this->artisan('loyalty:backfill', ['--user' => $target->id])->assertSuccessful();

        $this->assertSame(1, (int) LoyaltyAccount::forUser($target->id)->lifetime_trips);
        $this->assertSame(0, (int) LoyaltyAccount::forUser($other->id)->lifetime_trips);
    }
}
