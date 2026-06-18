<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แอดมินต้องแก้ไขการจองแบบมัดจำ (deposit) ได้ — รวมยอดมัดจำ ยอดส่วนที่เหลือ
 * และกำหนดชำระ ซึ่งก่อนหน้านี้ endpoint ปฏิเสธ payment_type=deposit
 */
class AdminUpdateDepositBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeDepositBooking(): Booking
    {
        $trip = Trip::create([
            'title' => 'Deposit Trip', 'slug' => 'deposit-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Pai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 5000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => 'LLK-DEP-0001',
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'payment_type' => 'deposit',
            'total_amount' => 5000,
            'paid_amount' => 2000,
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
            'balance_due_at' => now()->addWeeks(2)->toDateString(),
        ]);
    }

    public function test_admin_can_edit_deposit_booking_balance_fields(): void
    {
        $booking = $this->makeDepositBooking();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'payment_type' => 'deposit',
                'total_amount' => 5000,
                'paid_amount' => 2500,
                'deposit_amount' => 2500,
                'balance_amount' => 2500,
                'balance_due_at' => now()->addWeeks(3)->toDateString(),
                'balance_payment_ref' => 'BALREF-99',
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame('deposit', $booking->payment_type);
        $this->assertEquals(2500, (float) $booking->deposit_amount);
        $this->assertEquals(2500, (float) $booking->balance_amount);
        $this->assertSame('BALREF-99', $booking->balance_payment_ref);
    }
}
