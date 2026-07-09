<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchedulePaymentSlipsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Slip Trip',
            'slug' => 'slip-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 2000,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeBooking(TripSchedule $schedule, array $attributes): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => 'LLK-'.now()->format('Ymd').'-'.strtoupper(uniqid()),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 2000,
            'paid_amount' => 2000,
            'payment_method' => 'promptpay',
        ], $attributes));
    }

    public function test_full_payment_booking_exposes_its_slip(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, [
            'payment_type' => 'full',
            'slip_path' => 'slips/2026/07/full.jpg',
            'slip_ocr_status' => 'verified',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/payments")
            ->assertOk();

        $entries = $response->json('data.0.entries');

        $this->assertSame($booking->booking_ref, $response->json('data.0.booking_ref'));
        $this->assertCount(1, $entries);
        $this->assertSame('full', $entries[0]['key']);
        $this->assertSame('paid', $entries[0]['status']);
        $this->assertSame('verified', $entries[0]['slip_ocr_status']);
        $this->assertNotNull($entries[0]['slip_url']);
    }

    public function test_deposit_booking_exposes_deposit_and_balance_slips(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeBooking($schedule, [
            'payment_type' => 'deposit',
            'paid_amount' => 500,
            'deposit_amount' => 500,
            'balance_amount' => 1500,
            'balance_due_at' => now()->addWeek(),
            'slip_path' => 'slips/2026/07/deposit.jpg',
            'balance_slip_path' => 'slips/2026/07/balance.jpg',
            'paid_at' => now(),
        ]);

        $booking = $this->actingAs($this->makeAdmin())
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/payments")
            ->assertOk()
            ->json('data.0');

        $entries = $booking['entries'];

        $this->assertCount(2, $entries);
        $this->assertSame('deposit', $entries[0]['key']);
        $this->assertEquals(500, $entries[0]['amount']);
        $this->assertNotNull($entries[0]['slip_url']);

        $this->assertSame('balance', $entries[1]['key']);
        $this->assertEquals(1500, $entries[1]['amount']);
        $this->assertSame('pending', $entries[1]['status']);
        $this->assertNotNull($entries[1]['slip_url']);

        $this->assertEquals(1500, $booking['outstanding_amount']);
    }

    public function test_installment_booking_exposes_one_entry_per_installment(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, [
            'payment_type' => 'installment',
            'paid_amount' => 1000,
            'installment_count' => 2,
            // งวดที่ 1 ใช้สลิปใบเดียวกับ booking — ต้องไม่โผล่ซ้ำเป็นรายการแยก
            'slip_path' => 'slips/2026/07/inst-1.jpg',
        ]);

        foreach ([1, 2] as $no) {
            InstallmentPayment::create([
                'booking_id' => $booking->id,
                'installment_no' => $no,
                'amount' => 1000,
                'due_date' => now()->addWeeks($no)->toDateString(),
                'status' => $no === 1 ? 'paid' : 'pending',
                'paid_at' => $no === 1 ? now() : null,
                'slip_path' => $no === 1 ? 'slips/2026/07/inst-1.jpg' : null,
            ]);
        }

        $entries = $this->actingAs($this->makeAdmin())
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/payments")
            ->assertOk()
            ->json('data.0.entries');

        $this->assertCount(2, $entries);
        $this->assertSame('installment-1', $entries[0]['key']);
        $this->assertSame('งวดที่ 1', $entries[0]['label']);
        $this->assertNotNull($entries[0]['slip_url']);

        $this->assertSame('installment-2', $entries[1]['key']);
        $this->assertSame('pending', $entries[1]['status']);
        $this->assertNull($entries[1]['slip_url']);
    }

    public function test_cancelled_bookings_are_excluded(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeBooking($schedule, ['payment_type' => 'full', 'status' => 'cancelled']);

        $this->actingAs($this->makeAdmin())
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/payments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_endpoint_requires_admin(): void
    {
        $schedule = $this->makeSchedule();

        $this->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/payments")
            ->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/admin/calendar/schedules/{$schedule->id}/payments")
            ->assertForbidden();
    }
}
