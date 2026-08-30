<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แอดมินจองผ่านแอปแล้วข้ามหน้าชำระเงิน — ใบจองต้องยืนยันทันที
 * แต่ห้ามนับเป็นเงินเข้า และห้ามใช้ได้จากบัญชีอื่น
 */
class AdminSkipPaymentBookingTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Skip Payment Trip',
            'slug' => 'skip-payment-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function passengers(): array
    {
        return [[
            'title' => 'นาย',
            'name' => 'ผู้เดินทาง หนึ่ง',
            'nickname' => 'หนึ่ง',
            'id_card' => '1234567890121',
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ]];
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_booking_with_skip_payment_is_confirmed_immediately(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();

        $response = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $schedule->id,
            'passengers' => $this->passengers(),
            'skip_payment' => true,
        ]);

        $response->assertCreated();
        $this->assertSame('confirmed', $response->json('data.status'));

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        // ไม่มีเงินเข้า — รายงานรายรับรวม paid_amount จึงต้องไม่ขยับ
        $this->assertEquals(0.0, (float) $booking->paid_amount);
        $this->assertEquals(1500.0, (float) $booking->total_amount);
        $this->assertSame(Booking::PAYMENT_METHOD_ADMIN_SKIP, $booking->payment_method);
        $this->assertNotNull($booking->paid_at);
        // ที่นั่งถูกหักจากรอบแล้วเหมือนการจองที่ชำระเงินจริง
        $this->assertSame(1, (int) $schedule->fresh()->booked_seats);
    }

    public function test_customer_cannot_skip_payment(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();
        $customer = User::factory()->create();

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $schedule->id,
            'passengers' => $this->passengers(),
            'skip_payment' => true,
        ]);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('data.status'));

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertSame('pending', $booking->status);
        $this->assertNull($booking->paid_at);
    }

    public function test_admin_without_the_flag_still_gets_a_pending_booking(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();

        $response = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $schedule->id,
            'passengers' => $this->passengers(),
        ]);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('data.status'));
    }
}
