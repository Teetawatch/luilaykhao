<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "ล็อกที่นั่งไว้ก่อน" — แอดมินจองแทนลูกค้าแบบกรอกครบทุกอย่าง (รวมเลือกที่นั่ง)
 * แต่ข้ามขั้นชำระเงิน แล้วกันที่นั่งไว้ให้จนถึงเวลาที่กำหนด
 */
class AdminManualHoldBookingTest extends TestCase
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

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Manual Hold Trip', 'slug' => 'manual-hold-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2000, 'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ], $overrides));
    }

    private function passengerPayload(string $name = 'สมชาย ใจดี'): array
    {
        return [
            'title' => 'นาย',
            'name' => $name,
            'phone' => '0810000000',
            'id_card' => '1234567890123',
            'emergency_contact' => 'สมหญิง',
            'emergency_phone' => '0820000000',
            'halal_food' => false,
        ];
    }

    public function test_admin_can_hold_seats_with_full_details_and_skip_payment(): void
    {
        $schedule = $this->makeSchedule();
        $holdUntil = now()->addDays(3);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'hold@example.test',
                'passengers' => [$this->passengerPayload()],
                'seat_ids' => ['A1'],
                'status' => 'pending',
                'payment_type' => 'full',
                'hold_until' => $holdUntil->toIso8601String(),
                'hold_note' => 'ลูกค้าโทรมาจอง ขอโอนวันศุกร์',
                'send_email' => false,
            ])
            ->assertCreated();

        $booking = Booking::where('booking_ref', $res->json('data.booking_ref'))->firstOrFail();

        $this->assertSame('pending', $booking->status);
        $this->assertEqualsWithDelta($holdUntil->timestamp, $booking->hold_until->timestamp, 60);
        $this->assertSame('ลูกค้าโทรมาจอง ขอโอนวันศุกร์', $booking->hold_note);
        $this->assertSame($this->admin->id, $booking->hold_by_id);
        $this->assertEquals(0, (float) $booking->paid_amount);
        $this->assertNull($booking->slip_path);

        // ที่นั่งถูกจับจองจริง ไม่ใช่แค่บันทึกชื่อไว้
        $this->assertDatabaseHas('booking_seats', ['booking_id' => $booking->id, 'seat_id' => 'A1']);
        $this->assertSame(1, (int) $schedule->fresh()->booked_seats);

        // เส้นตายที่ส่งให้ client คือเวลาที่ล็อกไว้ ไม่ใช่สิบนาทีมาตรฐาน
        $this->assertEqualsWithDelta(
            $holdUntil->timestamp,
            strtotime($res->json('data.expires_at')),
            60,
        );
    }

    public function test_hold_booking_survives_the_ten_minute_expiry_sweep(): void
    {
        $schedule = $this->makeSchedule();

        $booking = $this->createHoldBooking($schedule, now()->addDays(2));
        $booking->update(['created_at' => now()->subHours(3)]);

        $expired = app(BookingService::class)->expireStalePendingBookings();

        $this->assertSame(0, $expired);
        $this->assertSame('pending', $booking->fresh()->status);
        $this->assertDatabaseHas('booking_seats', ['booking_id' => $booking->id]);
    }

    public function test_seats_return_when_the_hold_deadline_passes(): void
    {
        $schedule = $this->makeSchedule();

        $booking = $this->createHoldBooking($schedule, now()->addDays(2));
        $booking->forceFill([
            'created_at' => now()->subDays(3),
            'hold_until' => now()->subMinute(),
        ])->save();

        $expired = app(BookingService::class)->expireStalePendingBookings();

        $booking->refresh();
        $this->assertSame(1, $expired);
        $this->assertSame('cancelled', $booking->status);
        $this->assertStringContainsString('ล็อกที่นั่ง', $booking->cancellation_reason);
        // ไม่ใช่ลูกค้าทิ้งตะกร้า — win-back ต้องไม่ตามใบนี้
        $this->assertFalse((bool) $booking->was_auto_expired);
        $this->assertSame(0, BookingSeat::where('booking_id', $booking->id)->count());
        $this->assertSame(0, (int) $schedule->fresh()->booked_seats);
    }

    public function test_join_trip_booking_can_be_held_without_taking_a_van_seat(): void
    {
        $schedule = $this->makeSchedule([
            'join_trip_enabled' => true,
            'join_trip_price' => 900,
            'join_trip_seats' => 5,
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'จอย ทริป',
                'phone' => '0830000000',
                'email' => 'join-hold@example.test',
                'passengers' => [$this->passengerPayload('จอย ทริป')],
                'is_join_trip' => true,
                'status' => 'pending',
                'payment_type' => 'full',
                'hold_until' => now()->addDays(2)->toIso8601String(),
                'send_email' => false,
            ])
            ->assertCreated();

        $booking = Booking::where('booking_ref', $res->json('data.booking_ref'))->firstOrFail();
        $schedule->refresh();

        $this->assertTrue((bool) $booking->is_join_trip);
        $this->assertNotNull($booking->hold_until);
        $this->assertEquals(900, (float) $booking->total_amount);
        $this->assertSame(0, BookingSeat::where('booking_id', $booking->id)->count());
        $this->assertSame(0, (int) $schedule->booked_seats);
        $this->assertSame(1, (int) $schedule->join_trip_booked_seats);
    }

    public function test_hold_never_runs_past_departure(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now()->addDays(2)->toDateString(),
            'return_date' => now()->addDays(3)->toDateString(),
        ]);

        $booking = $this->createHoldBooking($schedule, now()->addDays(20));

        $this->assertTrue($booking->hold_until->lte(now()->addDays(3)));
    }

    public function test_hold_beyond_the_cap_is_rejected(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now()->addDays(120)->toDateString(),
            'return_date' => now()->addDays(121)->toDateString(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'ล็อกยาว เกินไป',
                'phone' => '0840000000',
                'passengers' => [$this->passengerPayload('ล็อกยาว เกินไป')],
                'seat_ids' => ['A1'],
                'status' => 'pending',
                'hold_until' => now()->addDays(45)->toIso8601String(),
                'send_email' => false,
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_extend_an_existing_hold(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->createHoldBooking($schedule, now()->addDay());
        $newDeadline = now()->addDays(5);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/hold", [
                'hold_until' => $newDeadline->toIso8601String(),
                'hold_note' => 'ลูกค้าขอเลื่อนโอนเป็นวันจันทร์',
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta($newDeadline->timestamp, $booking->hold_until->timestamp, 60);
        $this->assertSame('ลูกค้าขอเลื่อนโอนเป็นวันจันทร์', $booking->hold_note);
    }

    public function test_a_paid_booking_cannot_be_put_on_hold(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->createHoldBooking($schedule, now()->addDay());
        $booking->update(['status' => 'confirmed']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/hold", [
                'hold_until' => now()->addDays(2)->toIso8601String(),
            ])
            ->assertStatus(422);
    }

    private function createHoldBooking(TripSchedule $schedule, \DateTimeInterface $holdUntil): Booking
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'hold-'.uniqid().'@example.test',
                'passengers' => [$this->passengerPayload()],
                'seat_ids' => ['A1'],
                'status' => 'pending',
                'payment_type' => 'full',
                'hold_until' => $holdUntil->format(\DATE_ATOM),
                'send_email' => false,
            ])
            ->assertCreated();

        return Booking::where('booking_ref', $res->json('data.booking_ref'))->firstOrFail();
    }
}
