<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SosWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function confirmedBooking(User $user, string $departure, string $return): Booking
    {
        $trip = Trip::create([
            'title' => 'SOS Trip',
            'slug' => 'sos-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $return,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
    }

    public function test_sos_allowed_one_day_before_departure(): void
    {
        Bus::fake();
        // วันนี้ = 1 วันก่อนเดินทาง
        Carbon::setTestNow(Carbon::parse('2026-05-06 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertOk();

        $this->assertDatabaseHas('sos_alerts', [
            'user_id' => $user->id,
            'schedule_id' => $booking->schedule_id,
            'status' => 'active',
        ]);
    }

    public function test_sos_rejected_two_days_before_departure(): void
    {
        Bus::fake();
        // วันนี้ = 2 วันก่อนเดินทาง (ยังไม่ถึงช่วงที่อนุญาต)
        Carbon::setTestNow(Carbon::parse('2026-05-05 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertStatus(422);

        $this->assertDatabaseCount('sos_alerts', 0);
    }

    /**
     * รถกลับดีเลย์ข้ามเที่ยงคืนเป็นเรื่องปกติ และเป็นช่วงที่คนบนรถต้องการ SOS
     * มากที่สุด — เดิมระบบตัดตรงเที่ยงคืนของวันกลับพอดี
     */
    public function test_sos_still_works_the_day_after_the_return_date(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-09 01:30:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertOk();
    }

    public function test_sos_closes_two_days_after_the_return_date(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-10 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertStatus(422);
    }

    /**
     * รอบที่รถออกคืนก่อนวันทริป — ฝั่งแอปเปิดปุ่มโดยนับจาก departs_at อยู่แล้ว
     * backend ต้องนับฐานเดียวกัน ไม่งั้นปุ่มโผล่แต่กดแล้วโดนปฏิเสธ
     */
    public function test_sos_window_follows_departs_at_when_the_van_leaves_the_night_before(): void
    {
        Bus::fake();
        // รถออก 23:30 ของวันที่ 6 → วันเปิด SOS คือวันที่ 5
        Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');
        $booking->schedule->update(['departs_at' => '2026-05-06 23:30:00']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertOk();
    }

    public function test_traveller_can_close_their_own_false_alarm(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $alertId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertOk()
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sos/{$alertId}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }

    /**
     * เคสที่ยังเปิดอยู่ต้องรวมของตัวเองด้วย — คนที่กดแล้วแบตหมด เปิดเครื่องมาใหม่
     * ต้องเห็นว่าเคสตัวเองยังค้างอยู่และกดปิดได้
     */
    public function test_active_endpoint_returns_own_alert_flagged_as_mine(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sos/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_mine', true);
    }

    /**
     * เคสเก่าที่ไม่มีใครกดปิดต้องไม่เด้งไซเรนใส่คนที่เพิ่งเปิดแอปหลังทริปจบ
     */
    public function test_active_endpoint_ignores_alerts_older_than_a_day(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-05-09 09:00:00', 'Asia/Bangkok'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sos/active')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
