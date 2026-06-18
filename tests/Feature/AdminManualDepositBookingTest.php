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
 * แอดมินสร้างการจองด้วยตนเองแบบมัดจำได้ เมื่อรอบเดินทางเปิดมัดจำไว้
 * และระบบต้องคำนวณยอดมัดจำ/ยอดส่วนที่เหลือ/กำหนดชำระให้อัตโนมัติ
 */
class AdminManualDepositBookingTest extends TestCase
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

    private function makeDepositSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Manual Deposit Trip', 'slug' => 'manual-deposit-'.uniqid(), 'type' => 'trekking',
            'location' => 'Chiang Mai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 5000, 'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
            'deposit_enabled' => true, 'deposit_type' => 'percent', 'deposit_percent' => 30,
        ], $overrides));
    }

    public function test_admin_can_create_manual_deposit_booking(): void
    {
        $schedule = $this->makeDepositSchedule();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'name' => 'สมหญิง', 'surname' => 'ทดสอบ',
                'phone' => '0810000000',
                'passenger_count' => 1,
                'status' => 'pending',
                'payment_type' => 'deposit',
                'payment_method' => 'promptpay',
                'send_email' => false,
            ])
            ->assertCreated();

        $booking = Booking::where('booking_ref', $res->json('data.booking_ref'))->firstOrFail();

        $this->assertSame('deposit', $booking->payment_type);
        $this->assertEquals(1500, (float) $booking->deposit_amount);   // 30% ของ 5000
        $this->assertEquals(3500, (float) $booking->balance_amount);
        $this->assertNotNull($booking->balance_due_at);
        $this->assertSame(
            $schedule->departure_date->copy()->subDays(15)->toDateString(),
            $booking->balance_due_at->toDateString(),
        );
    }

    public function test_manual_deposit_rejected_when_schedule_has_no_deposit(): void
    {
        $schedule = $this->makeDepositSchedule(['deposit_enabled' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'name' => 'สมชาย', 'surname' => 'ทดสอบ',
                'phone' => '0810000001',
                'passenger_count' => 1,
                'status' => 'pending',
                'payment_type' => 'deposit',
                'send_email' => false,
            ])
            ->assertStatus(422);
    }
}
