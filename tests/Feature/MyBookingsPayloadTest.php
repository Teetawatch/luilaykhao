<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ข้อมูลที่หน้า "การจองของฉัน" ในแอปต้องได้รับ — โดยเฉพาะเส้นตายที่ระบบจะยกเลิก
 * การจองอัตโนมัติ ซึ่งเดิมไม่เคยส่งมา ลูกค้าจึงไม่รู้ตัวว่าที่นั่งกำลังจะหลุด
 */
class MyBookingsPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เขาหลวง',
            'slug' => 'khao-luang-'.uniqid(),
            'type' => 'trekking',
            'location' => 'นครศรีธรรมราช',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 4200,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDays(2)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeBooking(TripSchedule $schedule, User $user, array $attributes = []): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'pending',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 4200,
            ...$attributes,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'ผู้เดินทาง',
            'nickname' => 'ต้น',
            'phone' => '0810000000',
        ]);

        $schedule->syncBookedSeats();

        return $booking;
    }

    public function test_pending_booking_carries_the_auto_cancel_deadline(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $booking = $this->makeBooking($schedule, $user);

        $expiresAt = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->json('data.0.expires_at');

        $this->assertNotNull($expiresAt, 'แอปต้องรู้ว่าที่นั่งจะถูกคืนเมื่อไหร่');
        $this->assertSame(
            $booking->created_at->addMinutes(Booking::PENDING_TTL_MINUTES)->toISOString(),
            $expiresAt,
        );
    }

    public function test_a_booking_waiting_for_slip_review_has_no_deadline(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        // ส่งสลิปแล้วยอดไม่ตรง → ถือที่นั่งไว้รอแอดมิน ไม่มีการนับถอยหลัง
        $this->makeBooking($schedule, $user, ['slip_ocr_status' => 'pending_review']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.expires_at', null)
            ->assertJsonPath('data.0.slip_ocr_status', 'pending_review');
    }

    public function test_confirmed_booking_has_no_deadline(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->makeBooking($schedule, $user, ['status' => 'confirmed']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.expires_at', null);
    }

    /**
     * รายชื่อผู้ร่วมเดินทางในรอบ (ใช้แสดงอวาตาร์) ต้องยังมาครบ แม้จะดึงเฉพาะ
     * คอลัมน์ที่จำเป็นเพื่อเลี่ยงการถอดรหัสฟิลด์ที่เข้ารหัสไว้ของทุกคน
     */
    public function test_round_travellers_still_arrive_for_the_avatar_row(): void
    {
        $schedule = $this->makeSchedule();
        $me = User::factory()->create();
        $this->makeBooking($schedule, $me, ['status' => 'confirmed']);
        $this->makeBooking($schedule, User::factory()->create(), ['status' => 'confirmed']);

        $travelers = $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->json('data.0.schedule.travelers');

        $this->assertCount(2, $travelers);
        $this->assertSame('ต้น', $travelers[0]['name']);
        $this->assertArrayHasKey('is_self', $travelers[0]);
    }

    public function test_customer_can_cancel_their_own_unpaid_booking(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $booking = $this->makeBooking($schedule, $user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/cancel", [
                'reason' => 'ลูกค้ายกเลิกเอง',
            ])
            ->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(0, $schedule->fresh()->booked_seats);
    }
}
